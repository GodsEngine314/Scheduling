<?php

namespace App\Console\Commands;

use App\Support\Integrations\Humanity\HumanityClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Write Humanity's position catalogue to the file HumanitySeeder reads.
 *
 * THE LAST STEP THAT MAKES PUBLISHING POSSIBLE. `schedule` is required on
 * POST /shifts and it is Humanity's id for a position at a location; until the
 * catalogue holds those ids, every shift is refused before a request is made.
 *
 * WHY A FILE AND NOT A DIRECT SEED. The seeder has always read exports, and
 * keeping that seam means it stays runnable with no token — on a developer's
 * machine, in a test, and in whatever environment comes next. This command is
 * the half that needs credentials; the seeder is the half that needs none.
 *
 * READ ONLY. GET /positions creates nothing and changes nothing, which is why
 * this is safe to run whenever a position is added in Humanity. Publishing is
 * the only thing in this service that writes to the vendor.
 */
class ExportHumanityPositionsCommand extends Command
{
    protected $signature = 'humanity:export-positions
        {--include-deleted : Include positions Humanity has retired, recorded inactive rather than dropped}
        {--path= : Where to write; defaults to storage/app/integrations/humanity-positions.json}';

    protected $description = 'Export GET /positions to the file HumanitySeeder reads, so published shifts can name a schedule.';

    public function handle(HumanityClient $humanity): int
    {
        $path = (string) ($this->option('path')
            ?: storage_path('app/integrations/humanity-positions.json'));

        try {
            $positions = $humanity->positions((bool) $this->option('include-deleted'));
        } catch (Throwable $e) {
            $this->error('Humanity refused the request: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($positions === []) {
            // Refused rather than written. The seeder treats an empty export as
            // "leave the catalogue alone" precisely because an empty answer is
            // far likelier to be a permissions problem than an account with no
            // positions — so writing the file would only move the confusion.
            $this->error(
                'Humanity returned no positions. The account may lack permission for GET /positions; '
                .'nothing was written.'
            );

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        /**
         * The vendor envelope, rebuilt rather than dumped verbatim.
         *
         * A Humanity response carries a `token` field holding a LIVE ACCESS
         * TOKEN. Writing the raw response would leave a working credential in a
         * file on disk — so only status and data are kept, and the token never
         * lands anywhere.
         */
        $written = file_put_contents($path, json_encode([
            'status' => 1,
            'data' => $positions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($written === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $located = count(array_filter(
            $positions,
            static fn (array $position): bool => ($position['location']['id'] ?? null) !== null,
        ));

        $this->info(count($positions).' position(s) written to '.$path.'.');

        if ($located < count($positions)) {
            // A position with no location cannot be tied to one of our stores by
            // id. The seeder falls back to the store token in the name, so this
            // is a note rather than a failure.
            $this->warn(
                (count($positions) - $located).' of them name no location, so the seeder will have to read '
                .'the store out of the position name instead.'
            );
        }

        $this->newLine();
        $this->line('Next: php artisan db:seed --class=HumanitySeeder');

        return self::SUCCESS;
    }
}
