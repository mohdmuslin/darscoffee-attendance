<?php

namespace App\Services;

use App\Enums\TimeEntryType;
use App\Models\Employee;
use App\Models\PunchSession;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * What the employee's screen should show, and what they may do next.
 *
 * Derived entirely server-side. If the phone decided which buttons to offer, a stale
 * or tampered client could present "clock in" while already clocked in — and the
 * database would refuse, but the employee would be left staring at an error with no
 * idea why.
 */
class PunchStateService
{
    public function __construct(private readonly PhotoService $photos) {}

    /**
     * @return array<string, mixed>
     */
    public function for(PunchSession $session): array
    {
        $employee = $session->employee;
        $open = app(PunchService::class)->openSegment($employee);

        $state = $this->stateName($open);

        return [
            'state' => $state,
            'label' => $this->label($state),
            'since' => $open?->started_at?->toIso8601String(),
            'actions' => $this->actions($state),
            'today' => $this->todayTotals($employee, $session->outlet->timezone),
            /*
             * Who and where, so a refreshed page can still show the header. The session
             * token is persisted on the phone, but its employee and outlet were only ever
             * held in the response to /punch/start — so a reload mid-shift left the screen
             * with no name and no outlet, which looks like being logged out even though
             * every button still works.
             */
            'subject' => [
                // First name only, matching the start response.
                'name' => explode(' ', trim((string) $employee->name))[0],
                'employee_code' => $employee->employee_code,
                'photo_url' => $this->photos->temporaryUrl($employee->photo_path),
                'outlet' => $session->outlet->name,
                'requires_photo' => (bool) $session->outlet->requires_photo,
            ],
        ];
    }

    private function stateName(?TimeEntry $open): string
    {
        if ($open === null) {
            return 'clocked_out';
        }

        return $open->type === TimeEntryType::BREAK ? 'on_break' : 'working';
    }

    private function label(string $state): string
    {
        return match ($state) {
            'clocked_out' => 'Not clocked in',
            'working' => 'Working',
            'on_break' => 'On break',
        };
    }

    /**
     * The actions valid in this state.
     *
     * Exactly the transitions the service accepts, so the screen never offers a button
     * that will fail.
     *
     * @return array<int, array<string, string>>
     */
    private function actions(string $state): array
    {
        return match ($state) {
            'clocked_out' => [
                ['action' => 'clock_in', 'label' => 'Clock in', 'tone' => 'primary'],
            ],
            'working' => [
                ['action' => 'start_break', 'label' => 'Start break', 'tone' => 'muted'],
                ['action' => 'clock_out', 'label' => 'Clock out', 'tone' => 'primary'],
            ],
            'on_break' => [
                ['action' => 'end_break', 'label' => 'End break', 'tone' => 'primary'],
                ['action' => 'clock_out', 'label' => 'Clock out', 'tone' => 'muted'],
            ],
        };
    }

    /**
     * Worked, break and span totals for the current business day.
     *
     * Worked excludes breaks — the figure that is paid. Span is shown as well because
     * the difference between them is what a break costs, which is the thing employees
     * most often query.
     *
     * @return array<string, mixed>
     */
    public function todayTotals(Employee $employee, string $timezone): array
    {
        $businessDate = CarbonImmutable::now($timezone)->toDateString();

        $entries = TimeEntry::query()
            ->where('employee_id', $employee->id)
            // whereDate: business_date is date-cast and would otherwise bind as a
            // datetime. See TimeEntry::scopeForBusinessDate.
            ->whereDate('business_date', $businessDate)
            ->get();

        $worked = $entries->where('type', TimeEntryType::WORK)->sum(fn (TimeEntry $e) => $e->durationSeconds());
        $break = $entries->where('type', TimeEntryType::BREAK)->sum(fn (TimeEntry $e) => $e->durationSeconds());

        return [
            'business_date' => $businessDate,
            'worked_seconds' => $worked,
            'worked_label' => $this->hm($worked),
            'break_seconds' => $break,
            'break_label' => $this->hm($break),
            'has_open_segment' => $entries->contains(fn (TimeEntry $e) => $e->ended_at === null),
        ];
    }

    /**
     * This week's worked hours, by day.
     *
     * @return array<string, mixed>
     */
    public function weeklySummary(Employee $employee): array
    {
        $start = CarbonImmutable::now()->startOfWeek();

        $entries = TimeEntry::query()
            ->work()
            ->closed()
            ->where('employee_id', $employee->id)
            // whereDate for the same reason as above: the column is date-cast.
            ->whereDate('business_date', '>=', $start->toDateString())
            ->get()
            ->groupBy(fn (TimeEntry $entry) => $entry->business_date->toDateString());

        $days = [];
        $total = 0;

        foreach ($entries as $date => $group) {
            $seconds = $group->sum(fn (TimeEntry $entry) => $entry->durationSeconds());
            $total += $seconds;

            $days[] = [
                'date' => $date,
                'weekday' => CarbonImmutable::parse($date)->format('D'),
                'seconds' => $seconds,
                'label' => $this->hm($seconds),
            ];
        }

        return [
            'week_starting' => $start->toDateString(),
            'total_seconds' => $total,
            'total_label' => $this->hm($total),
            'days' => $days,
        ];
    }

    /** Seconds as "8h 30m", the form a timesheet is read in. */
    private function hm(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }
}
