<?php

namespace App\Services\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Exception;
use Throwable;

class JetStreamPublisher
{
    /**
     * Build a fresh client per publish to avoid stale credentials issues.
     */
    private function makeClient(): Client
    {
        $host = (string) config('nats.host');
        $port = (int) config('nats.port');

        if ($host === '' || $port <= 0) {
            throw new Exception('NATS host/port not configured (nats.host / nats.port).');
        }

        $token = config('nats.token');
        $user = config('nats.user');
        $pass = config('nats.pass');

        $opts = [
            'host' => $host,
            'port' => $port,
        ];

        if (!empty($token)) {
            $opts['token'] = (string) $token;
        } elseif (!empty($user) || !empty($pass)) {
            if (empty($user) || empty($pass)) {
                throw new Exception('NATS user/pass auth requires BOTH nats.user and nats.pass.');
            }

            $opts['user'] = (string) $user;
            $opts['pass'] = (string) $pass;
        } else {
            throw new Exception('NATS auth not configured (set nats.token OR nats.user+nats.pass).');
        }

        return new Client(new Configuration($opts));
    }

    /**
     * Publish an event to the correct stream based on subject.
     *
     * @return array{stream?:string, seq?:int, duplicate?:bool}
     */
    public function publish(string $subject, array $payload): array
    {
        $target = $this->resolvePublishTarget($subject);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('Failed to encode event payload as JSON.');
        }

        $streamName = (string) ($target['name'] ?? '');
        if ($streamName === '') {
            throw new Exception("Resolved publish target for subject '{$subject}' has no stream name.");
        }

        $client = $this->makeClient();

        try {
            $stream = $client->getApi()->getStream($streamName);
            $ack = $stream->put($subject, $json);

            if ($ack === null || $ack === false) {
                throw new Exception('JetStream publish did not return an ACK.');
            }

            $ackArr = $this->normalizeAck($ack);

            if (isset($ackArr['stream']) && $ackArr['stream'] !== $streamName) {
                throw new Exception(
                    "JetStream ACK stream mismatch. Expected '{$streamName}', got '{$ackArr['stream']}'."
                );
            }

            if (isset($ackArr['seq']) && (!is_int($ackArr['seq']) || $ackArr['seq'] <= 0)) {
                throw new Exception('JetStream ACK returned invalid sequence number.');
            }

            if (isset($ackArr['error']) && $ackArr['error']) {
                throw new Exception('JetStream publish error: ' . (string) $ackArr['error']);
            }

            return $ackArr;
        } catch (Throwable $e) {
            throw new Exception(
                "JetStream publish failed for subject '{$subject}' (stream '{$streamName}'): " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Resolve which configured publisher target should handle this subject.
     *
     * @return array{name:string,subjects:array<int,string>}
     */
    private function resolvePublishTarget(string $subject): array
    {
        $publishers = (array) config('nats.publishers', []);

        if (count($publishers) === 0) {
            throw new Exception('No publish targets configured in nats.publishers.');
        }

        foreach ($publishers as $publisher) {
            $patterns = (array) ($publisher['subjects'] ?? []);

            foreach ($patterns as $pattern) {
                $pattern = (string) $pattern;

                if ($pattern !== '' && $this->matchesNatsSubject($subject, $pattern)) {
                    return $publisher;
                }
            }
        }

        throw new Exception("No publish target configured for subject '{$subject}'.");
    }

    /**
     * Normalize ACK into simple array.
     *
     * @return array<string,mixed>
     */
    private function normalizeAck(mixed $ack): array
    {
        if (is_array($ack)) {
            return $ack;
        }

        if (is_string($ack)) {
            $decoded = json_decode($ack, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return ['raw' => $ack];
        }

        if (is_object($ack)) {
            if (method_exists($ack, 'toArray')) {
                $arr = $ack->toArray();
                return is_array($arr) ? $arr : ['raw' => (string) $ack];
            }

            $arr = get_object_vars($ack);
            if (is_array($arr) && count($arr) > 0) {
                return $arr;
            }

            if ($ack instanceof \JsonSerializable) {
                $arr = $ack->jsonSerialize();
                return is_array($arr) ? $arr : ['raw' => json_encode($arr)];
            }

            return ['raw' => (string) $ack];
        }

        return ['raw' => $ack];
    }

    /**
     * Minimal NATS subject matcher:
     * - ">" matches remaining tokens, only at end
     * - "*" matches exactly one token
     */
    private function matchesNatsSubject(string $subject, string $pattern): bool
    {
        $s = explode('.', $subject);
        $p = explode('.', $pattern);

        $si = 0;
        $pi = 0;

        while ($pi < count($p)) {
            $pt = $p[$pi];

            if ($pt === '>') {
                return $pi === count($p) - 1;
            }

            if ($si >= count($s)) {
                return false;
            }

            if ($pt !== '*' && $pt !== $s[$si]) {
                return false;
            }

            $si++;
            $pi++;
        }

        return $si === count($s);
    }
}
