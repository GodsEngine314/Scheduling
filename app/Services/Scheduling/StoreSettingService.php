<?php

namespace App\Services\Scheduling;

use App\Exceptions\SchedulingException;
use App\Models\StoreSetting;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Per-store scheduling settings.
 *
 * SCHEDULING-OWNED, and the only table here a stream replay must never touch —
 * auth's store events do not carry a timezone, so it cannot live on the stores
 * projection without a rebuild erasing it.
 *
 * TIMEZONE IS THE LOAD-BEARING COLUMN, and changing it is not a display
 * preference. It decides which calendar day a UTC instant belongs to, so it
 * decides every shift's business_date and which day an overnight shift is filed
 * under. A store moved from New York to Phoenix re-reads its whole history at a
 * different offset: shifts near midnight change day. Nothing is rewritten —
 * business_date is stored, not derived on read — so the change applies to what
 * is written NEXT, and the existing rows keep the day they were filed under.
 * That is the honest behaviour, but it means the two can disagree either side of
 * a change, which is why update() refuses to do it quietly.
 *
 * THE STATIC CACHE IS THE TRAP. BusinessDay memoises store_id => timezone in a
 * private STATIC array so a request resolving fifty shifts does one lookup. A
 * static outlives the request in anything long-running — a queue worker, a
 * scheduler loop — so a write here that does not flush it leaves those processes
 * resolving business dates against the old zone until they restart. update()
 * flushes the process it runs in; it cannot reach the others, and says so.
 */
class StoreSettingService
{
    /**
     * The settings row for a store, creating it from the column defaults if
     * this store has never had one.
     *
     * A store arriving from auth has no settings row, and every reader falls
     * back to the default zone rather than failing — which is the right
     * behaviour for a board and the wrong one for a settings screen, where
     * "nothing configured" has to be visible and editable.
     */
    public function forStore(int $storeId): StoreSetting
    {
        $setting = StoreSetting::query()->where('store_id', $storeId)->first();

        if ($setting !== null) {
            return $setting;
        }

        // Not saved. An unsaved model shows the defaults the columns would take
        // without writing a row for a store nobody has configured — the first
        // actual save is what creates it.
        return StoreSetting::query()->make([
            'store_id' => $storeId,
            'timezone' => BusinessDay::DEFAULT_TIMEZONE,
            'publish_lead_days' => 14,
            'auto_publish' => false,
        ]);
    }

    /**
     * Write the settings for a store.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $storeId, array $attributes): StoreSetting
    {
        $timezone = $attributes['timezone'] ?? null;

        if ($timezone !== null && ! in_array($timezone, timezone_identifiers_list(), true)) {
            // Checked here and not only in the form request: a bad zone reaches
            // CarbonImmutable::parse() as a fatal, and the callers of
            // BusinessDay are every screen in the app.
            throw new SchedulingException('Not a timezone this server recognises.', [
                'timezone' => $timezone,
            ]);
        }

        $setting = DB::transaction(function () use ($storeId, $attributes): StoreSetting {
            $setting = StoreSetting::query()->firstOrNew(['store_id' => $storeId]);

            $setting->forceFill(array_intersect_key($attributes, array_flip([
                'timezone',
                'day_close_cutoff_time',
                'publish_lead_days',
                'auto_publish',
            ])))->save();

            return $setting;
        });

        // Immediately, and before anything else reads a business_date in this
        // process. See the class docblock for what this cannot reach.
        BusinessDay::flushTimezoneCache();

        return $setting;
    }
}
