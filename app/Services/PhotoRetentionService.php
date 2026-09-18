<?php

namespace App\Services;

use App\Enums\CorrectionStatus;
use App\Models\Anomaly;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * PDPA minimisation: delete photographs once they have served their purpose.
 *
 * WHY THIS IS A SERVICE AND NOT JUST A CALL TO PhotoService::purgeFolderOlderThan()
 *
 * Deleting on file age alone is unsafe. A punch photo is evidence — it is the whole
 * reason the outlet requires one — and some of those photos are attached to disputes
 * that are still open. Deleting the photo that settles an argument because the
 * calendar says ninety days have passed destroys the one thing that could resolve it,
 * and does so silently.
 *
 * So the retention decision is made from the DATABASE, where the dispute state lives,
 * and file age is only the backstop for files no row refers to any more.
 *
 * WHY LOCKED PAY PERIODS ARE NOT AN INDEFINITE HOLD
 *
 * It is tempting to keep every photo belonging to a locked pay period, since that is
 * when money was approved. But a locked period stays locked forever, so that rule
 * would mean nothing is ever purged and retention would quietly not happen at all.
 * The retention window already covers the ordinary dispute window — a period is locked
 * after its punches, so those punches still have the remainder of their window ahead
 * of them. What genuinely needs an open-ended hold is a dispute nobody has closed yet,
 * and those are the two holds below.
 *
 * The result is a file whose only protection is age, plus four paths in the database
 * that point at it. Both are dealt with together: the file goes, and the column that
 * named it is cleared, so the console never renders a placeholder for a photo that a
 * record claims exists.
 */
class PhotoRetentionService
{
    /** Punch photos captured at the counter. The overwhelming majority of the data. */
    public const PUNCH_FOLDER = 'punches';

    /** Profile photos, one per employee, held until they leave. */
    public const EMPLOYEE_FOLDER = 'employees';

    /**
     * Days a photo is kept. Owner-configurable, because the right window is a policy
     * question for the business, not a technical one.
     */
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(
        private readonly PhotoService $photos,
    ) {}

    /** The configured window, falling back when unset or nonsensical. */
    public function retentionDays(): int
    {
        return Setting::int(Setting::PHOTO_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Run retention. Returns a report rather than a bare count so the cron output and
     * the `--dry-run` preview say the same thing, and so a run that deleted nothing
     * can be told apart from a run that could not find its data.
     *
     * @return array{
     *     retention_days: int,
     *     cutoff: string,
     *     dry_run: bool,
     *     photos: array{purged: int, held: int, orphans: int, missing: int},
     *     profiles: array{purged: int, held: int},
     *     holds: array<string, int>
     * }
     */
    public function run(?int $days = null, bool $dryRun = false): array
    {
        $days = $days ?? $this->retentionDays();
        $cutoff = CarbonImmutable::now()->subDays($days);

        $punch = $this->purgePunchPhotos($cutoff, $dryRun);
        $profiles = $this->purgeProfilePhotos($cutoff, $dryRun);

        return [
            'retention_days' => $days,
            'cutoff' => $cutoff->toDateString(),
            'dry_run' => $dryRun,
            'photos' => $punch,
            'profiles' => $profiles,
            'holds' => $punch['holds'],
        ];
    }

    /**
     * Purge punch photos past the window, except those still in play.
     *
     * @return array{purged: int, held: int, orphans: int, missing: int, holds: array<string, int>}
     */
    private function purgePunchPhotos(CarbonImmutable $cutoff, bool $dryRun): array
    {
        /*
         * Every path the database still names, WHATEVER its age. This is what protects
         * a file from the orphan sweep: a file is only an orphan when no row mentions
         * it, and collecting this from the eligibility query instead would have made
         * every recent photo look unreferenced.
         */
        $referenced = TimeEntry::query()
            ->where(function ($query) {
                $query->whereNotNull('started_photo_path')
                    ->orWhereNotNull('ended_photo_path');
            })
            ->get(['id', 'started_photo_path', 'ended_photo_path'])
            ->flatMap(fn (TimeEntry $entry) => array_filter([
                $entry->started_photo_path,
                $entry->ended_photo_path,
            ]))
            ->flip()
            ->all();

        /*
         * Each photo is aged by ITS OWN timestamp, not the segment's start.
         *
         * A shift beginning at 22:00 and ending at 06:00 has a clock-out photo taken
         * eight hours after the clock-in one. Judging both by `started_at` would delete
         * the clock-out photo eight hours before it was actually due, and on an
         * overnight shift that is the difference between the retention window meaning
         * ninety days and meaning eighty-nine.
         */
        $candidates = [];

        foreach ([
            ['column' => 'started_photo_path', 'stamp' => 'started_at'],
            ['column' => 'ended_photo_path', 'stamp' => 'ended_at'],
        ] as $photo) {
            $rows = TimeEntry::query()
                ->whereNotNull($photo['column'])
                ->whereNotNull($photo['stamp'])
                ->where($photo['stamp'], '<', $cutoff)
                ->get(['id', $photo['column']]);

            foreach ($rows as $row) {
                $candidates[] = [
                    'entry_id' => $row->id,
                    'column' => $photo['column'],
                    'path' => $row->{$photo['column']},
                ];
            }
        }

        if ($candidates === []) {
            $orphans = $dryRun
                ? $this->countOrphans($referenced, $cutoff)
                : $this->sweepOrphans($referenced, $cutoff);

            return ['purged' => 0, 'held' => 0, 'orphans' => $orphans, 'missing' => 0, 'holds' => []];
        }

        $held = $this->heldEntryIds(array_column($candidates, 'entry_id'));

        $purged = 0;
        $kept = 0;
        $missing = 0;
        $holdReasons = [];

        /** @var array<int, array<int, string>> $clear Entry id => columns to blank. */
        $clear = [];

        foreach ($candidates as $candidate) {
            $entryId = $candidate['entry_id'];

            if (isset($held[$entryId])) {
                // Held photos stay on disk AND keep their row, so the console can still
                // show the evidence the hold exists to preserve.
                foreach ($held[$entryId] as $reason) {
                    $holdReasons[$reason] = ($holdReasons[$reason] ?? 0) + 1;
                }

                $kept++;

                continue;
            }

            if ($this->photos->exists($candidate['path'])) {
                if (! $dryRun) {
                    $this->photos->delete($candidate['path']);
                }

                $purged++;
            } else {
                /*
                 * The file is already gone but the row still names it. Clearing the
                 * column is the whole point of the run for this row — otherwise the
                 * console keeps offering to show a photo that does not exist.
                 */
                $missing++;
            }

            $clear[$entryId][] = $candidate['column'];
        }

        if (! $dryRun) {
            foreach ($clear as $entryId => $columns) {
                /*
                 * `toBase()`, and that is the whole point of this line.
                 *
                 * Eloquent's builder adds `updated_at` to any `update()`, so the plain
                 * version would stamp every purged row. On a table whose `updated_at` is
                 * read as "these times were altered", a nightly housekeeping pass would
                 * then look like a mass edit of historical attendance — exactly the
                 * signature a manager investigating a dispute would chase, and it would
                 * be a false trail created by the retention job itself.
                 *
                 * Nothing about the recorded hours changes here: the columns being cleared
                 * hold paths to photographs, never times, types or durations. Dropping to
                 * the base query keeps that distinction visible in the data.
                 */
                TimeEntry::query()
                    ->whereKey($entryId)
                    ->toBase()
                    ->update(array_fill_keys($columns, null));
            }
        }

        $orphans = $dryRun
            ? $this->countOrphans($referenced, $cutoff)
            : $this->sweepOrphans($referenced, $cutoff);

        return [
            'purged' => $purged,
            'held' => $kept,
            'orphans' => $orphans,
            'missing' => $missing,
            'holds' => $holdReasons,
        ];
    }

    /**
     * Entries whose photos must survive this run, keyed by entry id.
     *
     * Two holds, both resolvable by someone doing their job:
     *
     *  - an anomaly nobody has reviewed, which is exactly the case where the photo is
     *    the evidence;
     *  - a correction still pending, where the photo may be what the decision turns on.
     *
     * @param  array<int, int>  $entryIds
     * @return array<int, array<int, string>> Entry id => hold reasons.
     */
    private function heldEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $held = [];

        $unreviewed = Anomaly::query()
            ->whereIn('time_entry_id', $entryIds)
            ->whereNull('reviewed_at')
            ->pluck('time_entry_id');

        foreach ($unreviewed as $id) {
            $held[$id][] = 'unreviewed_anomaly';
        }

        $pending = AttendanceCorrection::query()
            ->whereIn('time_entry_id', $entryIds)
            ->where('status', CorrectionStatus::PENDING->value)
            ->pluck('time_entry_id');

        foreach ($pending as $id) {
            $held[$id][] = 'pending_correction';
        }

        return $held;
    }

    /**
     * Delete files in the punch folder that no row refers to any more.
     *
     * These are the ones ordinary retention cannot reach: an upload written by a
     * request that then failed, or a row deleted outright. They are personal data with
     * no record keeping them alive, so they are swept on age alone.
     *
     * @param  array<string, bool>  $referenced
     */
    private function sweepOrphans(array $referenced, CarbonImmutable $cutoff): int
    {
        if (! Storage::disk('local')->exists(self::PUNCH_FOLDER)) {
            return 0;
        }

        $deleted = 0;

        foreach (Storage::disk('local')->files(self::PUNCH_FOLDER) as $file) {
            if (isset($referenced[$file])) {
                continue;
            }

            if (Storage::disk('local')->lastModified($file) < $cutoff->getTimestamp()) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param array<string, bool> $referenced */
    private function countOrphans(array $referenced, CarbonImmutable $cutoff): int
    {
        if (! Storage::disk('local')->exists(self::PUNCH_FOLDER)) {
            return 0;
        }

        $count = 0;

        foreach (Storage::disk('local')->files(self::PUNCH_FOLDER) as $file) {
            if (! isset($referenced[$file])
                && Storage::disk('local')->lastModified($file) < $cutoff->getTimestamp()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Purge profile photos once the person has been gone long enough.
     *
     * A profile photo is not punch evidence, so a dispute hold does not apply. The
     * trigger is leaving: while someone works here their photo serves the roster and
     * the console, and once they have left it serves nothing and is indefensible to
     * keep. Employees still active are never purged, however old the file is — that is
     * why this does not go through `purgeFolderOlderThan()` at all.
     *
     * @return array{purged: int, held: int}
     */
    private function purgeProfilePhotos(CarbonImmutable $cutoff, bool $dryRun): array
    {
        $departed = Employee::withTrashed()
            ->whereNotNull('photo_path')
            ->where(function ($query) use ($cutoff) {
                $query->where('resigned_at', '<', $cutoff->toDateString())
                    // A hard-removed employee has no resignation date; the soft-delete
                    // timestamp is then the moment they stopped being part of the business.
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('resigned_at')
                            ->whereNotNull('deleted_at')
                            ->where('deleted_at', '<', $cutoff);
                    });
            })
            ->get();

        $purged = 0;

        foreach ($departed as $employee) {
            if ($dryRun) {
                $purged++;

                continue;
            }

            $this->photos->delete($employee->photo_path);

            // Cleared through the query builder so a soft-deleted row is still updated
            // and the model's `deleted_at` is left alone.
            Employee::withTrashed()
                ->whereKey($employee->id)
                ->update(['photo_path' => null]);

            $purged++;
        }

        return ['purged' => $purged, 'held' => 0];
    }
}
