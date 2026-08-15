<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\TcpJobCodeRole;
use App\Support\Integrations\Tcp\TcpClient;
use Illuminate\Database\Seeder;

/**
 * TCP's job codes, reduced to the handful of ROLES behind them, so a punch can
 * say which position was worked.
 *
 * WHY THE ROLE AND NOT THE CODE. A TCP job code encodes three things at once:
 *
 *     jobCodeId 37954202   "Crew Leader - 3795-42"
 *                ^^^^ ^^ ^^
 *                3795 42 02    franchise, store, role
 *
 * There are 237 of them because the same six roles are repeated per store. They
 * are not 237 positions — they are six positions and a store column this schema
 * already has on the row.
 *
 * AND ONE MAPPING PER POSITION IS ALL THE SCHEMA ALLOWS. integration_identities
 * is UNIQUE(entity_type, entity_id, system), so a position can hold exactly one
 * TCP external_id; filing all 38 "Crew Leader" codes against the Crew Leader
 * position is not expressible there, and the alternative — a position per store
 * per role — puts 237 entries in the shift dropdown and fills a PROJECTION table
 * with rows hiring never sent.
 *
 * So what is mapped is the ROLE SUFFIX: external_id '02' means "the last two
 * digits of a per-store job code", and WorkSegmentSyncService::resolvePositionId
 * falls back to it after trying the whole code. That keeps the exact-code lookup
 * working for anyone who files one by hand, and it means a NEW STORE needs no
 * seeding at all — 37954902 decodes through the same '02'.
 *
 * COMPANY-WIDE CODES ARE LEFT UNMAPPED, deliberately. Regular, Training,
 * Tipping, Bonus, Sick, Meal Penalty and Rest Penalty are four-digit pay
 * categories, not positions; "Regular" names how an hour is paid, not what
 * somebody did. Filing hours under a position nobody worked would corrupt the
 * labour cost report more quietly than leaving the column null — the same rule
 * resolvePositionId() already follows.
 *
 * Idempotent and additive. Positions are matched by label, so re-running adopts
 * what is already there rather than duplicating it, and the positions DemoSeeder
 * created are untouched.
 */
class PositionSeeder extends Seeder
{
    /** A per-store job code: franchise (4) + store (2) + role (2). */
    private const PER_STORE_CODE = '/^(\d{4})(\d{2})(\d{2})$/';

    public function run(): void
    {
        try {
            $codes = app(TcpClient::class)->jobCodes();
        } catch (\Throwable $e) {
            $this->command?->warn('PositionSeeder: could not read job codes from TCP — '.$e->getMessage());

            return;
        }

        if ($codes === []) {
            $this->command?->warn('PositionSeeder: TCP returned no job codes.');

            return;
        }

        // role suffix => ['label' => string, 'codes' => int]
        $roles = [];
        $companyWide = 0;
        $conflicts = [];

        foreach ($codes as $code) {
            $jobCodeId = trim((string) ($code['jobCodeId'] ?? ''));

            if (preg_match(self::PER_STORE_CODE, $jobCodeId, $parts) !== 1) {
                $companyWide++;

                continue;
            }

            $suffix = $parts[3];
            $label = $this->roleLabel((string) ($code['description'] ?? ''));

            if ($label === null) {
                continue;
            }

            if (isset($roles[$suffix]) && $roles[$suffix]['label'] !== $label) {
                // The same role suffix naming two different things somewhere in
                // the estate. Reported rather than silently resolved: it would
                // file one store's hours under another store's role, and the
                // first label to arrive would win by accident of ordering.
                $conflicts[$suffix][] = $label;

                continue;
            }

            $roles[$suffix] ??= ['label' => $label, 'codes' => 0];
            $roles[$suffix]['codes']++;
        }

        ksort($roles);

        $positions = 0;

        foreach ($roles as $suffix => $role) {
            // Matched by LABEL, so the roles that share a name share a position:
            // suffix 04 and suffix 08 are both Assistant Manager, and they are
            // one position with two ways of arriving at it. That many-to-one is
            // the whole reason tcp_job_code_roles exists rather than a row in
            // integration_identities, which is unique per entity and would have
            // kept whichever suffix was written last.
            $position = Position::query()->firstOrCreate(
                ['label' => $role['label']],
                ['description' => 'A TCP job code role.'],
            );

            if ($position->wasRecentlyCreated) {
                $positions++;
            }

            TcpJobCodeRole::query()->updateOrCreate(
                ['role_suffix' => $suffix],
                [
                    'tcp_label' => $role['label'],
                    'position_id' => (int) $position->id,
                    'code_count' => $role['codes'],
                ],
            );
        }

        $this->command?->info(
            'PositionSeeder: '.count($roles).' job code roles => '
            .collect($roles)->pluck('label')->unique()->count().' positions'
            .' ('.$positions.' newly created), from '.count($codes).' TCP job codes.'
            .' '.$companyWide.' company-wide codes left unmapped on purpose.'
        );

        // One store out of thirty-nine is the shape of an anomaly, not a rule.
        foreach ($roles as $suffix => $role) {
            if ($role['codes'] <= 2) {
                $this->command?->warn(
                    "PositionSeeder: role {$suffix} ('{$role['label']}') appears at only "
                    .$role['codes'].' store(s) — worth confirming it is not a typo at TCP.'
                );
            }
        }

        foreach ($conflicts as $suffix => $labels) {
            $this->command?->warn(
                "PositionSeeder: role suffix {$suffix} is also used for '"
                .implode("', '", array_unique($labels))."' — left on '".$roles[$suffix]['label']."'."
            );
        }
    }

    /**
     * "Crew Leader - 3795-42" is the Crew Leader role. "Management" is too — a
     * per-store code whose description carries no store suffix, which is why
     * the CODE decides whether something is per-store and the description only
     * supplies the name.
     */
    private function roleLabel(string $description): ?string
    {
        $label = trim(explode(' - ', $description, 2)[0]);

        return $label === '' ? null : $label;
    }

}
