<?php

namespace App\Services;

use App\Enums\ConsentMethod;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PDPA consent for holding a staff member's photograph.
 *
 * WHY A SERVICE RATHER THAN A CONTROLLER METHOD
 *
 * Two rules meet here that are easy to get individually wrong and dangerous together:
 *
 *  1. WITHDRAWAL MUST ACT, NOT JUST RECORD.
 *
 *     A withdrawal that sets a timestamp and changes nothing else is theatre. The employee
 *     has said "stop photographing me", and the system's answer must be that no further
 *     photograph is taken. That is what `revokePhotoRequirement` arranges at the outlet.
 *
 *  2. WITHDRAWAL MUST NOT DELETE EVIDENCE.
 *
 *     The tempting next step is to delete every photo of the person. That would destroy
 *     punch photographs that are evidence in an open dispute — and those photographs are
 *     not solely the employee's: they are the record of what happened, and a manager facing
 *     a wage claim needs them. So withdrawal stops FUTURE capture, and past photographs
 *     follow the ordinary retention window. A person who wants them gone sooner asks, and
 *     the owner acts on that request having weighed it — which is what the Act actually
 *     provides for, and is not a decision this code should make on its own.
 *
 * The result is deliberately modest: it records consent properly, makes withdrawal do the
 * one thing it unambiguously must do, and leaves the genuinely hard judgement — erasing
 * contested evidence — to a human.
 */
class ConsentService
{
    /**
     * Current notice version.
     *
     * Stamped onto every consent so the record can answer "what did they agree to". Bump
     * this whenever the notice's substance changes; a consent to version 1 does not cover
     * a materially different version 2.
     */
    public const NOTICE_VERSION = '1.0';

    /**
     * Record consent on behalf of an employee.
     *
     * The recorder is stored, not inferred. A consent record with no attributed recorder is
     * exactly as weak as no record when it is challenged, which defeats the purpose.
     */
    public function record(
        Employee $employee,
        ConsentMethod $method,
        User $recordedBy,
        ?string $version = null,
        ?string $note = null,
    ): Employee {
        $employee->recordConsent(
            $method,
            $recordedBy,
            $version ?? self::NOTICE_VERSION,
            $note,
        );

        return $employee->refresh();
    }

    /**
     * Withdraw consent, and stop collecting photographs from now on.
     *
     * Withdrawal is recorded, the profile photograph is deleted, and — because the punch
     * flow asks `Employee::isPhotographRequired()` rather than reading the outlet flag
     * alone — no further punch photograph is taken from this person. That last part is what
     * makes the withdrawal act instead of merely being noted.
     */
    public function withdraw(Employee $employee, ?string $note = null): Employee
    {
        return DB::transaction(function () use ($employee, $note) {
            $employee->withdrawConsent($note);

            /*
             * The profile photograph goes, because it is not evidence of anything — it is a
             * picture kept for convenience, shown on the roster and beside punches, and
             * there is no reason to hold it once the person has objected.
             *
             * Punch photographs are deliberately NOT touched here; see the class comment.
             */
            if (filled($employee->photo_path)) {
                app(PhotoService::class)->delete($employee->photo_path);

                Employee::query()
                    ->whereKey($employee->id)
                    ->toBase()
                    ->update(['photo_path' => null]);
            }

            return $employee->refresh();
        });
    }

    /**
     * Employees whose photograph is held with no consent on record.
     *
     * The console needs this list: it is the compliance backlog, and it is not something a
     * manager can be expected to assemble by eye.
     *
     * @return Collection<int, Employee>
     */
    public function withoutConsent(?User $viewer = null)
    {
        return Employee::query()
            ->with('outlets')
            ->when($viewer !== null, fn ($q) => $q->visibleTo($viewer))
            ->whereNotNull('photo_path')
            ->where(function ($q) {
                $q->whereNull('consent_at')
                    ->orWhereNotNull('consent_withdrawn_at');
            })
            ->orderBy('name')
            ->get();
    }
}
