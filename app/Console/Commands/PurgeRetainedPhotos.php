<?php

namespace App\Console\Commands;

use App\Services\PhotoRetentionService;
use Illuminate\Console\Command;

/**
 * Delete photographs once they have served their purpose.
 *
 * Scheduled nightly rather than run by hand, because a retention policy that depends
 * on someone remembering is a retention policy that does not happen. On the cPanel
 * target the cron entry calls this through `schedule:run`.
 *
 * `--dry-run` exists because the first run on a live database should not be the one
 * that finds out what the rule actually matches. Get the preview, read the numbers,
 * then let it run.
 */
class PurgeRetainedPhotos extends Command
{
    protected $signature = 'attendance:purge-photos
                            {--days= : Override the configured retention window, for a one-off catch-up run}
                            {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete punch and profile photographs past the retention window (PDPA minimisation)';

    public function handle(PhotoRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $days = $this->option('days');
        $days = $days === null ? null : (int) $days;

        if ($days !== null && $days < 1) {
            $this->error('--days must be a positive number of days.');

            return self::FAILURE;
        }

        $report = $retention->run($days, $dryRun);

        $this->line(sprintf(
            '%s: keeping photographs for %d days, so anything before %s is eligible.',
            $dryRun ? 'Dry run' : 'Purge',
            $report['retention_days'],
            $report['cutoff'],
        ));

        $photos = $report['photos'];

        $this->line(sprintf(
            '  Punch photos: %d deleted, %d held as evidence, %d orphaned files %s, %d row(s) pointed at a file already gone.',
            $photos['purged'],
            $photos['held'],
            $photos['orphans'],
            $dryRun ? 'would be deleted' : 'deleted',
            $photos['missing'],
        ));

        $this->line(sprintf(
            '  Profile photos: %d %s.',
            $report['profiles']['purged'],
            $dryRun ? 'would be deleted' : 'deleted',
        ));

        /*
         * The holds are printed rather than just counted. A number that will not go
         * down is the signal that a dispute has been left open — and since held photos
         * are never purged, that is the only thing that will ever surface it.
         */
        foreach ($report['holds'] as $reason => $count) {
            $this->line(sprintf(
                '  Held by %s: %d entr(y/ies) — resolve these and the photos become eligible.',
                str_replace('_', ' ', $reason),
                $count,
            ));
        }

        if ($dryRun) {
            $this->comment('Nothing was deleted. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
