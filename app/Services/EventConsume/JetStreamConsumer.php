<?php

namespace App\Services\EventConsume;

use App\Models\EventInbox;
use App\Services\Nats\NatsClientFactory;
use Basis\Nats\Client;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JetStreamConsumer
{
    /**
     * After this many failures for the same event_id, we ACK/TERM it and park it.
     * This guarantees "stop trying" for that event_id on the application side.
     */
    private const MAX_PROCESSING_ATTEMPTS = 5;

    /**
     * Prevent hot spinning when the consumer loop itself errors (NATS down, auth, etc).
     */
    private const ERROR_BACKOFF_MS = 1000;

    /**
     * Delay for retries when handler fails (prevents tight redelivery loop).
     * NOTE: delay is best-effort; if client nack delay isn't supported, it will NAK without delay.
     */
    private const NACK_DELAY_SECONDS = 2;

    /**
     * Domain subjects prefix allowlist.
     * We ignore anything else (including internal control/status frames).
     *
     * Scheduling reads BOTH auth (users, stores) and hiring (employees), which is
     * the one place this consumer differs from HiringPizza's.
     */
    private const SUBJECT_ALLOW_PREFIXES = [
        'auth.v1.',
        'auth.testing.v1.',
        'hiring.v1.',
        'hiring.testing.v1.',
    ];

    private ?Client $client = null;

    /**
     * Cache consumer objects per stream+durable so we don't keep re-initializing.
     * @var array<string, mixed>
     */
    private array $consumerCache = [];

    /**
     * Unix timestamp of the last forced refresh/reconnect.
     */
    private int $lastRefreshAt = 0;

    /**
     * Refresh connection every 10 minutes to avoid stale idle pull consumers.
     */
    private const FORCE_REFRESH_SECONDS = 600;


    public function __construct(
        private readonly NatsClientFactory $factory,
        private readonly EventRouter $router,
    ) {
    }

    public function runForever(): void
    {
        $streams = (array) config('nats.streams', []);
        if (count($streams) === 0) {
            throw new Exception('No streams configured in nats.streams');
        }

        $batch = (int) config('nats.pull.batch', 25);
        $timeoutMs = (int) config('nats.pull.timeout_ms', 2000);
        $sleepMs = (int) config('nats.pull.sleep_ms', 250);

        // IMPORTANT: create ONE client and reuse it forever.
        $this->client = $this->factory->make();
        $this->lastRefreshAt = time();
        while (true) {
            try {
                if (!$this->isClientHealthy()) {
                    Log::warning('NATS connection stale, reconnecting');

                    $this->reconnect();
                }

                if ($this->shouldForceRefresh()) {
                    $this->reconnect('periodic_forced_refresh');
                }
                foreach ($streams as $cfg) {
                    $this->consumeStream($cfg, $batch, $timeoutMs);
                }

            } catch (Throwable $e) {
                Log::error('JetStream consumer outer loop error', [
                    'error' => $e->getMessage(),
                ]);

                $this->reconnect();
                usleep(self::ERROR_BACKOFF_MS * 1000);
            }

            usleep(max(1, $sleepMs) * 1000);
        }
    }
    private function shouldForceRefresh(): bool
    {
        if ($this->lastRefreshAt === 0) {
            return false;
        }

        return (time() - $this->lastRefreshAt) >= self::FORCE_REFRESH_SECONDS;
    }

    private function reconnect(string $reason = 'unknown'): void
    {
        Log::warning('Reconnecting JetStream consumer client', [
            'reason' => $reason,
        ]);

        try {
            if ($this->client && method_exists($this->client, 'disconnect')) {
                $this->client->disconnect();
            }
        } catch (Throwable $e) {
            Log::warning('NATS disconnect failed during reconnect', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        $this->client = $this->factory->make();
        $this->consumerCache = [];
        $this->lastRefreshAt = time();
    }
    /**
     * @param array{name:string,durable:string,filter_subject:string} $cfg
     */
    private function consumeStream(array $cfg, int $batch, int $timeoutMs): void
    {
        $streamName = (string) ($cfg['name'] ?? '');
        $durable = (string) ($cfg['durable'] ?? '');
        $filterSubject = (string) ($cfg['filter_subject'] ?? '>');

        if ($streamName === '' || $durable === '') {
            throw new Exception('Stream config requires name + durable');
        }

        if (!$this->client) {
            $this->client = $this->factory->make();
        }

        try {
            $consumer = $this->getOrInitConsumer($streamName, $durable, $filterSubject);

            // basis-company/nats.php pull-mode pattern:
            // - consumer->getQueue()->fetchAll($batch)
            $queue = $consumer->getQueue();

            // Library expects timeout in seconds
            $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
            if (method_exists($queue, 'setTimeout')) {
                $queue->setTimeout($timeoutSeconds);
            }

            $messages = $queue->fetchAll($batch);

            if (empty($messages)) {
                return;
            }

            foreach ($messages as $msg) {
                if ($msg === null) {
                    continue;
                }

                $this->handleMessage($msg, $streamName, $durable);
            }
        } catch (Throwable $e) {
            Log::error('JetStream consumer loop error', [
                'stream' => $streamName,
                'durable' => $durable,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            usleep(self::ERROR_BACKOFF_MS * 1000);
        }
    }


    private function isClientHealthy(): bool
    {
        try {
            // lightweight ping
            $this->client?->publish('_INBOX.healthcheck', 'ping');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    /**
     * Initializes and caches the JetStream consumer client-side object once.
     * NOTE: This does NOT create the consumer on the server; you already did via CLI.
     */
    private function getOrInitConsumer(string $streamName, string $durable, string $filterSubject)
    {
        $key = $streamName . '|' . $durable;

        if (isset($this->consumerCache[$key])) {
            return $this->consumerCache[$key];
        }

        $api = $this->client->getApi();
        $stream = $api->getStream($streamName);

        $consumer = $stream->getConsumer($durable);

        // Best-effort: set client-side filter (server filter is what actually matters).
        try {
            if (method_exists($consumer, 'getConfiguration')) {
                $cfg = $consumer->getConfiguration();
                if (is_object($cfg) && method_exists($cfg, 'setSubjectFilter')) {
                    $cfg->setSubjectFilter($filterSubject);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed setting consumer subject filter in client (continuing)', [
                'stream' => $streamName,
                'durable' => $durable,
                'filter_subject' => $filterSubject,
                'error' => $e->getMessage(),
            ]);
        }

        /**
         * IMPORTANT:
         * Do NOT call $consumer->create() here.
         * The consumer is already created via CLI.
         * Calling create() can produce internal request/reply traffic that some library versions
         * may surface into the same receive path as pull messages.
         */

        return $this->consumerCache[$key] = $consumer;
    }

    private function handleMessage($msg, string $streamName, string $durable): void
    {

        /**
         * Hard gate:
         * Only real JetStream deliveries have a $JS.ACK.* reply subject.
         * Control/status frames (and any internal inbox messages) must be ignored,
         * and MUST NOT be acked/termed/nacked (they're not part of ack pending).
         */
        $reply = $this->getMsgReply($msg);
        if (!$this->isJetStreamDelivery($reply)) {
            return;
        }

        $subject = $this->getMsgSubject($msg);

        // Domain allowlist: ignore anything not matching your domain prefixes
        // NOTE: for real JS deliveries, subject should already be the stream subject.
        if (!$this->isAllowedDomainSubject($subject)) {
            // Term it so it never blocks ack-pending.
            $this->termSafe($msg, $streamName, $durable, 'subject_not_allowed');
            return;
        }

        $raw = $this->extractBody($msg);
        if ($raw === '') {
            // Poison -> TERM it once so it never redelivers and never blocks.
            $this->termSafe($msg, $streamName, $durable, 'empty_payload');
            return;
        }

        $event = json_decode($raw, true);

        if (!is_array($event)) {
            $this->termSafe($msg, $streamName, $durable, 'non_json_payload');
            return;
        }

        $eventId = (string) ($event['id'] ?? '');
        $evtSubject = (string) ($event['subject'] ?? $event['type'] ?? '');
        $source = (string) ($event['source'] ?? '');

        if ($eventId === '' || $evtSubject === '') {
            $this->termSafe($msg, $streamName, $durable, 'missing_id_or_subject');
            return;
        }

        DB::beginTransaction();

        try {
            // Idempotency + attempts counter
            $inbox = EventInbox::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (!$inbox) {
                EventInbox::query()->create([
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
                    'source' => $source ?: null,
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'payload' => $event,
                    'processed_at' => null,
                    'attempts' => 0,
                    'parked_at' => null,
                    'last_error' => null,
                ]);

                $inbox = EventInbox::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
            }

            // If parked, never retry
            if ($inbox && $inbox->parked_at) {
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'already_parked');

                Log::warning('Event is parked - ACKed and skipped', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
                    'attempts' => (int) $inbox->attempts,
                    'parked_at' => $inbox->parked_at?->toDateTimeString(),
                ]);
                return;
            }

            // If processed, ACK and exit
            if ($inbox && $inbox->processed_at) {
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'already_processed');
                return;
            }

            try {
                $handlerClass = $this->router->resolve($evtSubject);

            } catch (Throwable $e) {
                // No handler defined for this subject -> not an error
                DB::commit();
                $this->ackSafe($msg, $streamName, $durable, 'no_handler_skip');
                return;
            }

            /** @var EventHandlerInterface $handler */
            $handler = app($handlerClass);
            $handler->handle($event);

            $inbox->processed_at = now();
            $inbox->last_error = null;
            $inbox->save();

            DB::commit();
            $this->ackSafe($msg, $streamName, $durable, 'processed_ok');
        } catch (Throwable $e) {
            // On handler failure: increment attempts and decide retry vs park
            try {
                $locked = EventInbox::query()
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($locked) {
                    $locked->attempts = (int) $locked->attempts + 1;
                    $locked->last_error = $e->getMessage();

                    if ($locked->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                        $locked->parked_at = now();
                        $locked->save();

                        DB::commit();

                        // Poison/too-many-attempts must NEVER stall the pipeline -> TERM it.
                        $this->termSafe($msg, $streamName, $durable, 'parked_max_attempts');
                        return;
                    }

                    $locked->save();

                    DB::commit();

                    // Retry by NAK (best effort delay)
                    $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'handler_failed_retry');
                    return;
                }

                DB::rollBack();
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'missing_inbox_row');
            } catch (Throwable $inner) {
                DB::rollBack();

                // If we cannot update attempts, we still must avoid stalling delivery:
                // NAK (retry) is safer than leaving it pending forever.
                $this->nackWithDelaySafe($msg, $streamName, $durable, self::NACK_DELAY_SECONDS, 'attempt_update_failed');

                Log::error('Event failed and attempts could not be updated - NAKed', [
                    'stream' => $streamName,
                    'consumer' => $durable,
                    'event_id' => $eventId,
                    'subject' => $evtSubject,
                    'original_error' => $e->getMessage(),
                    'attempts_update_error' => $inner->getMessage(),
                ]);
            }
        }
    }

    private function isJetStreamDelivery(?string $reply): bool
    {
        return is_string($reply) && $reply !== '' && str_starts_with($reply, '$JS.ACK.');
    }

    private function isAllowedDomainSubject(?string $subject): bool
    {
        if (!is_string($subject) || $subject === '')
            return false;

        foreach (self::SUBJECT_ALLOW_PREFIXES as $prefix) {
            if ($prefix !== '' && str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Most common for Basis\Nats\Message\Msg is: public string $payload
     */
    private function extractBody($msg): string
    {
        try {
            if (!is_object($msg))
                return '';

            if (property_exists($msg, 'payload') && is_string($msg->payload)) {
                return $msg->payload;
            }

            if (property_exists($msg, 'body') && is_string($msg->body)) {
                return $msg->body;
            }

            if (method_exists($msg, 'getBody')) {
                $b = $msg->getBody();
                if (is_string($b))
                    return $b;
            }

            if (method_exists($msg, '__toString')) {
                $s = (string) $msg;
                return $s !== '' ? $s : '';
            }
        } catch (Throwable $e) {
            // ignore
        }

        return '';
    }

    /**
     * Strong reply extractor: covers public props, getters, and protected/private props via reflection.
     */
    private function getMsgReply($msg): ?string
    {
        try {
            if (!is_object($msg))
                return null;

            foreach (['replyTo', 'reply_to', 'reply', 'replySubject', 'reply_subject'] as $k) {
                if (property_exists($msg, $k) && is_string($msg->{$k}) && $msg->{$k} !== '') {
                    return $msg->{$k};
                }
            }

            foreach (['getReplyTo', 'getReply', 'reply'] as $m) {
                if (method_exists($msg, $m)) {
                    $v = $msg->{$m}();
                    if (is_string($v) && $v !== '')
                        return $v;
                }
            }

            $ro = new \ReflectionObject($msg);
            foreach (['replyTo', 'reply', 'reply_subject', 'replySubject'] as $k) {
                if ($ro->hasProperty($k)) {
                    $p = $ro->getProperty($k);
                    $p->setAccessible(true);
                    $v = $p->getValue($msg);
                    if (is_string($v) && $v !== '')
                        return $v;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return null;
    }

    private function getMsgSubject($msg): ?string
    {
        try {
            if (is_object($msg) && property_exists($msg, 'subject') && is_string($msg->subject)) {
                return $msg->subject;
            }

            // Reflection fallback (some versions hide it)
            if (is_object($msg)) {
                $ro = new \ReflectionObject($msg);
                if ($ro->hasProperty('subject')) {
                    $p = $ro->getProperty('subject');
                    $p->setAccessible(true);
                    $v = $p->getValue($msg);
                    if (is_string($v) && $v !== '')
                        return $v;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * ACK that cannot stall the pipeline:
     * - Only for real JS deliveries
     * - Try library ack()
     * - Fallback: publish "+ACK" to the reply subject directly
     */
    private function ackSafe($msg, string $streamName, string $durable, string $reason): void
    {
        $reply = $this->getMsgReply($msg);
        if (!$this->isJetStreamDelivery($reply)) {
            return;
        }

        try {
            if (method_exists($msg, 'ack')) {
                $msg->ack();
                return;
            }
        } catch (Throwable $e) {
            Log::warning('ACK failed; will fallback to manual +ACK publish', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        // Manual ack (JetStream internal protocol): publish "+ACK" to reply subject
        try {
            $this->client?->publish($reply, '+ACK');
        } catch (Throwable $e) {
            Log::error('Manual +ACK publish failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * TERM that cannot stall the pipeline:
     * - Only for real JS deliveries
     * - Try library term()
     * - Fallback: publish "+TERM" to the reply subject directly
     */
    private function termSafe($msg, string $streamName, string $durable, string $reason): void
    {
        $reply = $this->getMsgReply($msg);
        if (!$this->isJetStreamDelivery($reply)) {
            return;
        }

        try {
            if (method_exists($msg, 'term')) {
                $msg->term();
                return;
            }
        } catch (Throwable $e) {
            Log::warning('TERM failed; will fallback to manual +TERM publish', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        // Manual term (JetStream internal protocol): publish "+TERM" to reply subject
        try {
            $this->client?->publish($reply, '+TERM');
        } catch (Throwable $e) {
            Log::error('Manual +TERM publish failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * NAK with delay best effort:
     * - Only for real JS deliveries
     * - Try library nack($delaySeconds) or nak()
     * - Fallback: publish "-NAK" (and if delay > 0, include JSON delay in nanoseconds)
     *
     * NOTE: If the server/client doesn't honor delay JSON for your version, it will still retry.
     * The critical goal here is: NEVER leave the message pending forever.
     */
    private function nackWithDelaySafe($msg, string $streamName, string $durable, int $delaySeconds, string $reason): void
    {
        $reply = $this->getMsgReply($msg);
        if (!$this->isJetStreamDelivery($reply)) {
            return;
        }

        try {
            if (method_exists($msg, 'nack')) {
                $msg->nack($delaySeconds);
                return;
            }

            if (method_exists($msg, 'nak')) {
                $msg->nak();
                return;
            }
        } catch (Throwable $e) {
            Log::warning('NACK/NAK failed; will fallback to manual -NAK publish', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'subject' => $this->getMsgSubject($msg),
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        // Manual nak (JetStream internal protocol): publish "-NAK" to reply subject
        try {
            $payload = '-NAK';

            // Best-effort delay format (nanoseconds) used by several clients/servers:
            if ($delaySeconds > 0) {
                $ns = (int) $delaySeconds * 1_000_000_000;
                $payload = '-NAK {"delay":' . $ns . '}';
            }

            $this->client?->publish($reply, $payload);
        } catch (Throwable $e) {
            Log::error('Manual -NAK publish failed', [
                'stream' => $streamName,
                'consumer' => $durable,
                'reason' => $reason,
                'reply' => $reply,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
