<?php

namespace App\Support\Integrations\LcData;

use App\Exceptions\IntegrationException;
use App\Services\Auth\DevBypass;
use App\Support\AuthContext;
use App\Support\Integrations\AbstractApiClient;
use App\Support\Integrations\TokenProvider;

/**
 * LC_PIZZA_DATA, the sales warehouse.
 *
 * Read-only, and the only thing scheduling asks it for is hourly sales.
 *
 * NOT A VENDOR. TCP and Humanity are outside systems with their own
 * credentials; this is a peer service behind the same auth server, and the
 * difference shows up in authDescriptor(): there is no client id, no secret and
 * no token exchange. The token this client sends is THE CALLER'S OWN, taken
 * from the current request and forwarded verbatim, so the warehouse's
 * `auth.token.store` middleware makes the same store-scope decision it would
 * make if the manager had called it directly.
 *
 * What that buys, and it is the whole reason for the design: scheduling cannot
 * read a store's revenue that the person looking at the screen could not read
 * for themselves. A service token here would be a standing key to every store's
 * takings, held by a service that has no business holding one.
 *
 * What it costs: OUTSIDE A REQUEST, THIS CLIENT DOES NOT WORK. No token, no
 * call. A queued job or an artisan command gets a configuration exception
 * rather than a silent empty result, because a caller in that position has made
 * a mistake and should hear about it.
 *
 * It still extends AbstractApiClient, which is where retry, backoff, the
 * correlation header and the key-names-not-values logging live. Those are worth
 * having on a peer call as much as on a vendor one.
 */
class LcDataClient extends AbstractApiClient
{
    private const HOURLY_SALES_PATH = '/reports/hourly-sales';

    public function __construct(
        TokenProvider $tokens,
        private readonly AuthContext $auth,
    ) {
        parent::__construct($tokens);
    }

    protected function integration(): string
    {
        return 'lc_data';
    }

    /**
     * The caller's token, presented the way the warehouse expects it.
     *
     * 'static' mode, because from AbstractApiClient's point of view that is
     * exactly what this is: a token handed over ready-made, with no exchange to
     * perform and nothing to refresh. The 401-retry path is oauth-only and is
     * correctly skipped — a rejected token here means the person's session has
     * expired, and no amount of asking again will change that.
     */
    protected function authDescriptor(): array
    {
        $token = trim((string) ($this->auth->token() ?? ''));

        // Neither an absent token nor the dev bypass's sentinel can be sent.
        // "Bearer " reads at the warehouse as a bad credential when it is
        // really a call made from somewhere with no caller, and the sentinel
        // produces a 401 that looks in the log exactly like an expired session.
        if ($token === '' || $token === DevBypass::SENTINEL) {
            $token = $this->fallbackToken($token);
        }

        return [
            'mode' => 'static',
            'transport' => 'header',
            'header' => 'Authorization',
            'prefix' => 'Bearer',
            'param' => null,
            'token' => $token,
        ];
    }

    /**
     * What to send when the caller has no usable token of their own.
     *
     * ONLY IN local AND testing, and the check is here in code rather than in
     * the config file for the reason DevBypass gives about its own: config
     * values travel in a .env, and a .env is the thing people copy to a server
     * by accident. In production this method has exactly one behaviour, which
     * is to refuse.
     *
     * The refusal names the ACTUAL cause. Both branches used to collapse into
     * one message on screen, which meant the commonest local setup — signed in
     * through the dev bypass — reported itself as a warehouse that was merely
     * unreachable, and sent people to check a service that was fine.
     */
    private function fallbackToken(string $found): string
    {
        $devToken = trim((string) (config('lc_data.dev_token') ?? ''));

        if ($devToken !== '' && app()->environment('local', 'testing')) {
            return $devToken;
        }

        if ($found === DevBypass::SENTINEL) {
            throw IntegrationException::configuration(
                'lc_data',
                'this session signed in through the dev bypass, which holds a sentinel string rather than a real auth-service token. Set LC_DATA_DEV_TOKEN to one LC_PIZZA_DATA accepts, or set LC_DATA_STUB=true to preview the column with sample figures.',
            );
        }

        throw IntegrationException::configuration(
            'lc_data',
            'there is no auth-service token on this request. LC_PIZZA_DATA is only readable on behalf of a signed-in caller.',
        );
    }

    /**
     * royalty_obligation by hour for one store, over a date range.
     *
     * $storeNumber is the FRANCHISE STORE NUMBER — the warehouse has never
     * heard of scheduling's store ids, and passing one would quietly return an
     * empty result rather than an error. See HourlySalesReader, which is the
     * only place that translation happens.
     *
     * @return array<string, array{by_hour: array<string,float>, day_total: float}>
     *         keyed by business date, one entry per day in the range
     */
    public function hourlySales(string $storeNumber, string $from, string $to): array
    {
        $response = $this->get(
            self::HOURLY_SALES_PATH.'/'.rawurlencode($storeNumber).'/'.rawurlencode($from),
            ['to' => $to],
        );

        $days = $response['days'] ?? null;

        if (! is_array($days)) {
            throw IntegrationException::guard(
                'lc_data',
                $this->endpoint(self::HOURLY_SALES_PATH),
                'The hourly-sales response had no days object.',
            );
        }

        $normalised = [];

        foreach ($days as $date => $day) {
            if (! is_string($date) || ! is_array($day)) {
                continue;
            }

            $byHour = [];

            foreach ((array) ($day['by_hour'] ?? []) as $hour => $amount) {
                if (! is_numeric($hour) || ! is_numeric($amount)) {
                    continue;
                }

                $byHour[(string) (int) $hour] = round((float) $amount, 2);
            }

            $normalised[$date] = [
                'by_hour' => $byHour,
                // The warehouse's own total for the WHOLE day, which is not the
                // same number as the sum of the displayed window. Carried
                // through rather than recomputed so the two never disagree.
                'day_total' => round((float) ($day['day_total'] ?? 0), 2),
            ];
        }

        return $normalised;
    }
}
