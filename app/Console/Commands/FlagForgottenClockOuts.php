<?php

namespace App\Console\Commands;

use App\Enums\AnomalyType;
use App\Models\Anomaly;
use App\Models\Setting;
use App\Models\TimeEntry;
use Illuminate\Console\Command;

/**
 * Flag segments that have been open far too long.
 *
 * WHY THIS NEEDS TO RUN ON A SCHEDULE
 *
 * A forgotten clock-out is the single most expensive attendance failure, and before this command
 * nothing noticed it. `PunchService::flagOnClose()` raises LONG_SPAN — but only when a segment
 * CLOSES, which is exactly the event that never happens when someone forgets to clock out. The
 * `MISSING_CLOCKOUT` anomaly type existed, was given HIGH severity, and was raised nowhere at all:
 * a check that could never fire.
 *
 * The consequence is a segment that runs for days. It inflates the worked total, distorts
 * overtime, and — because the open-segment invariant allows only one open segment per employee —
 * **prevents that person clocking in again at all.** The next morning they scan the code, enter
 * their PIN, press clock in, and nothing happens, because from the database's point of view they
 * never went home. Nobody at the counter can see why.
 *
 * Runs hourly so the flag appears while somebody is still on shift and can be asked about it,
 * rather than the next morning when the only remaining option is a manager guessing.
 */
class FlagForgottenClockOuts extends Command
{
    protected $signature = 'attendance:flag-open-segments
                            {--hours= : Override the threshold, in hours}
                            {--dry-run : Report what would be flagged without writing anything}';

    protected $description = 'Raise a MISSING_CLOCKOUT anomaly on segments left open too long';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $hours = $this->option('hours');
        $hours = $hours === null
            ? Setting::int(Setting::MISSING_CLOCKOUT_HOURS, 16)
            : (int) $hours;

        if ($hours < 1) {
            $this->error('--hours must be a positive number of hours.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        /*
         * Open segments whose START is older than the threshold.
         *
         * Filtered on `started_at` rather than on "how long the segment has run", because the
         * question is exactly "did this begin more than N hours ago and never finish" — and the
         * database can answer that with an indexed range.
         */
        $entries = TimeEntry::query()
            ->with(['employee', 'outlet'])
            ->whereNull('ended_at')
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($entries->isEmpty()) {
            $this->line(sprintf('Nothing open longer than %d hour(s).', $hours));

            return self::SUCCESS;
        }

        $flagged = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            /*
             * Raised once, not once per run.
             *
             * This command runs hourly for as long as the mistake stands, and without the check a
             * shift forgotten on Friday would have fifty identical anomalies by Monday — burying
             * every other flag in the review queue, which is the one place a manager looks.
             */
            $already = Anomaly::query()
                ->where('time_entry_id', $entry->id)
                ->where('type', AnomalyType::MISSING_CLOCKOUT->value)
                ->exists();

            if ($already) {
                $skipped++;

                continue;
            }

            $ranFor = (int) round($entry->started_at->diffInHours(now()));

            $detail = sprintf(
                'Still open after %d hour(s). Started %s at %s. Nobody has clocked out.',
                $ranFor,
                $entry->started_at->setTimezone(config('attendance.business_timezone'))->format('D d M H:i'),
                $entry->outlet?->name ?? 'an outlet',
            );

            if ($dryRun) {
                $this->line(sprintf(
                    '  would flag entry %d — %s (%s)',
                    $entry->id,
                    $entry->employee?->name ?? 'unknown',
                    $detail,
                ));

                continue;
            }

            Anomaly::raise($entry, AnomalyType::MISSING_CLOCKOUT, $detail);

            $this->line(sprintf(
                '  flagged entry %d — %s',
                $entry->id,
                $entry->employee?->name ?? 'unknown',
            ));

            $flagged++;
        }

        $this->line(sprintf(
            '%s %d segment(s) open longer than %d hour(s): %d flagged, %d already flagged.',
            $dryRun ? 'Dry run —' : 'Checked',
            $entries->count(),
            $hours,
            $flagged,
            $skipped,
        ));

        if ($dryRun) {
            $this->comment('Nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
