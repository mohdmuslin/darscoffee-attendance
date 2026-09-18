<?php

namespace App\Http\Controllers\Api\V1\Punch;

use App\Enums\PunchEventType;
use App\Http\Controllers\Controller;
use App\Models\PunchEvent;
use App\Models\PunchSession;
use App\Services\OfflinePunchService;
use App\Services\PhotoService;
use App\Services\PunchService;
use App\Services\PunchStateService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The public punch flow.
 *
 * NO LOGIN. Kitchen crew have no account and never will, so requiring one would mean
 * they cannot clock in at all. Identity comes from the outlet code plus a PIN.
 *
 * Every response is deliberately uninformative about WHY something failed. An attacker
 * probing codes or PINs learns only "no".
 */
class PunchController extends Controller
{
    public function __construct(
        private readonly PunchService $punch,
        private readonly PunchStateService $state,
        private readonly OfflinePunchService $offline,
    ) {}

    /**
     * Step 1: scan a code and prove a PIN, receiving a short-lived session.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'pin' => ['required', 'string', 'digits_between:4,6'],
        ]);

        /*
         * Rate-limited by IP as well as by PIN lockout, because the PIN lockout is
         * per-employee and would not slow an attacker walking a list of codes.
         */
        $key = 'punch-start:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return ApiResponse::error(
                'Too many attempts. Wait a minute and try again.',
                null,
                429,
                'RATE_LIMITED',
            );
        }

        $result = $this->punch->startSession(
            $validated['token'],
            $validated['pin'],
            $this->deviceClass($request),
            $request->ip(),
        );

        if ($result === null) {
            RateLimiter::hit($key, 60);

            /*
             * One message for every failure — wrong code, wrong PIN, locked PIN,
             * employee not mapped here. Distinguishing them would tell a prober which
             * codes are real and how close a PIN is.
             *
             * The employee is told to ask a manager rather than to retry, because a
             * wrong PIN and a lockout need the same remedy from their side.
             */
            return ApiResponse::error(
                'That code and PIN did not match. Check the PIN, or ask a manager to reset it.',
                null,
                401,
                'PIN_MISMATCH',
            );
        }

        RateLimiter::clear($key);

        $session = $result['session'];

        return ApiResponse::success([
            'punch_token' => $result['token'],
            'employee' => [
                // First name only: enough to confirm who you are, nothing more.
                'name' => $this->firstName($session->employee->name),
                'employee_code' => $session->employee->employee_code,
                'photo_url' => app(PhotoService::class)
                    ->temporaryUrl($session->employee->photo_path),
            ],
            'outlet' => [
                'name' => $session->outlet->name,
                'requires_photo' => $session->outlet->requires_photo,
            ],
            'expires_at' => $session->expires_at->toIso8601String(),
            'state' => $this->state->for($session),
        ], 'Welcome, '.$this->firstName($session->employee->name).'.');
    }

    /** Current state and the actions available, for refreshing the screen. */
    public function state(Request $request): JsonResponse
    {
        $session = $this->sessionFrom($request);

        if ($session === null) {
            return ApiResponse::error(
                'Your session has expired. Scan the code again.',
                null,
                401,
                'SESSION_EXPIRED',
            );
        }

        return ApiResponse::success(['state' => $this->state->for($session)]);
    }

    /**
     * Perform an action: clock in, start or end a break, or clock out.
     *
     * The photo arrives as base64 because the browser captured it on a canvas rather
     * than through a file input, so there is no UploadedFile to accept.
     */
    public function act(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:clock_in,start_break,end_break,clock_out'],
            'photo' => ['sometimes', 'nullable', 'string'],
            'client_uuid' => ['sometimes', 'nullable', 'uuid'],
            /*
             * When the punch actually happened, as reported by the phone. Present only for a
             * punch made while offline; absent for every ordinary punch, which is then stamped
             * with server time as always.
             *
             * This is the one field a client can lie about, so it is bounded rather than
             * trusted — see OfflinePunchService.
             */
            'claimed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $session = $this->sessionFrom($request);

        if ($session === null) {
            return ApiResponse::error(
                'Your session has expired. Scan the code again.',
                null,
                401,
                'SESSION_EXPIRED',
            );
        }

        /*
         * ------------------------------------------------------------------
         * Idempotency, checked FIRST — before the photo, before the action.
         * ------------------------------------------------------------------
         *
         * A queued punch is retried until it succeeds, and the phone cannot tell "the request
         * never arrived" from "the reply never arrived". So a retry may well be a punch this
         * server already recorded, and answering it as a fresh action would apply it twice — a
         * second clock-out, or a break closed at the wrong moment.
         *
         * Matched against the PUNCH TRAIL rather than the segment table. The first version used
         * `time_entries.client_uuid`, which only covers actions that create a segment: a
         * clock-out closes one and stored its id nowhere, so a retry was refused as out-of-order
         * and the employee was stranded on the clock. The trail records every action, so it is
         * the only ledger that can answer this for closures as well as creations.
         *
         * Safe against a genuine race because `punch_events.client_uuid` is UNIQUE: two
         * simultaneous duplicates cannot both insert, so the loser fails rather than quietly
         * applying the action a second time.
         *
         * Answered as a success, not a 409. The client is a phone draining a queue and can do
         * nothing useful with an error — it would retry for ever. Returning the stored result
         * lets the queue advance.
         */
        $applied = $this->offline->findAppliedEvent(
            $validated['client_uuid'] ?? null,
            (int) $session->employee_id,
        );

        if ($applied !== null) {
            PunchEvent::record(
                PunchEventType::DUPLICATE_IGNORED,
                employeeId: $session->employee_id,
                outletId: $session->outlet_id,
                tokenId: $session->outlet_token_id,
                timeEntryId: $applied->time_entry_id,
                ipAddress: $request->ip(),
                meta: ['action' => $validated['action']],
            );

            return ApiResponse::success([
                'state' => $this->state->for($session->fresh(['employee', 'outlet'])),
                'entry_id' => $applied->time_entry_id,
                'duplicate' => true,
            ], 'Already recorded — nothing was changed.');
        }

        $serverTime = CarbonImmutable::now();

        /*
         * A client-supplied time is bounded, not trusted. Out of bounds is a REFUSAL rather
         * than a clamp: silently recording a different time from the one claimed would leave
         * the employee unaware their punch had been altered, with nothing for a manager to
         * review. The correction path exists for exactly this case.
         */
        $at = $this->offline->resolveTimestamp($validated['claimed_at'] ?? null);

        if ($at === null) {
            PunchEvent::record(
                PunchEventType::REJECTED,
                employeeId: $session->employee_id,
                outletId: $session->outlet_id,
                tokenId: $session->outlet_token_id,
                ipAddress: $request->ip(),
                meta: [
                    'action' => $validated['action'],
                    'reason' => 'claimed_at_out_of_bounds',
                    'claimed_at' => $validated['claimed_at'] ?? null,
                ],
            );

            return ApiResponse::error(
                'That punch time is outside the window we can accept. Ask a manager to add it.',
                null,
                422,
                'CLAIMED_AT_OUT_OF_BOUNDS',
            );
        }

        $clientUuid = $validated['client_uuid'] ?? null;
        $isOffline = $this->offline->isOfflineReport($validated['claimed_at'] ?? null);

        /*
         * An out-of-order queue is refused rather than half-applied. A clock-out with nothing
         * open would create a segment ending before it started — a negative duration, which is
         * the corruption `durationSeconds()` clamps against.
         */
        try {
            $this->offline->assertActionIsCoherent(
                $validated['action'],
                $this->punch->openSegment($session->employee),
            );
        } catch (InvalidArgumentException $e) {
            PunchEvent::record(
                PunchEventType::REJECTED,
                employeeId: $session->employee_id,
                outletId: $session->outlet_id,
                tokenId: $session->outlet_token_id,
                ipAddress: $request->ip(),
                meta: ['action' => $validated['action'], 'reason' => 'out_of_order'],
            );

            return ApiResponse::error($e->getMessage(), null, 422, 'OUT_OF_ORDER_PUNCH');
        }

        [$photoBytes, $extension] = $this->decodePhoto($validated['photo'] ?? null);

        /*
         * A photo is required when the outlet asks for one AND this employee may be
         * photographed. Enforced here rather than only warned about, because a punch without
         * a photo at an outlet that requires them leaves nothing to settle a dispute with —
         * which is the whole point.
         *
         * Asked of the EMPLOYEE rather than read off the outlet, so that someone who has
         * withdrawn consent is never photographed and — just as importantly — is not blocked
         * from punching either. Their refusal removes the photo requirement for them; it must
         * not turn into not being able to clock in.
         */
        if ($session->employee->isPhotographRequired($session->outlet) && $photoBytes === null) {
            /*
             * Recorded as well as refused. "I took a photo and it wouldn't let me clock
             * in" is a real complaint, and without a trail row the only evidence is the
             * employee's word against a screen nobody kept.
             */
            PunchEvent::record(
                PunchEventType::REJECTED,
                employeeId: $session->employee_id,
                outletId: $session->outlet_id,
                tokenId: $session->outlet_token_id,
                ipAddress: $request->ip(),
                meta: ['action' => $validated['action'], 'reason' => 'photo_required'],
            );

            return ApiResponse::error(
                'This outlet needs a photo with every punch. Allow camera access and try again.',
                null,
                422,
                'PHOTO_REQUIRED',
            );
        }

        /*
         * The mirror case: a photo was taken but this person must not be photographed,
         * because they withdrew consent. DISCARDED rather than stored, and the punch goes
         * through — the person still worked.
         *
         * Asked of the EMPLOYEE alone (`mayBePhotographed`), not of the outlet. An outlet that
         * does not require photos still accepts a volunteered one; only a refusal by the
         * person forbids it.
         *
         * This can happen legitimately: the screen was rendered before the withdrawal, or the
         * browser camera was already open. It is recorded, because a photograph being taken
         * at all is the event the consent existed to prevent, and the owner should be able to
         * see it happening rather than have it disappear.
         */
        if ($photoBytes !== null && ! $session->employee->mayBePhotographed()) {
            PunchEvent::record(
                PunchEventType::REJECTED,
                employeeId: $session->employee_id,
                outletId: $session->outlet_id,
                tokenId: $session->outlet_token_id,
                ipAddress: $request->ip(),
                meta: ['action' => $validated['action'], 'reason' => 'consent_withdrawn_photo_discarded'],
            );

            $photoBytes = null;
        }

        $entry = match ($validated['action']) {
            'clock_in' => $this->punch->clockIn($session, $photoBytes, $extension, $at, $clientUuid),
            'start_break' => $this->punch->startBreak($session, $photoBytes, $extension, $at, $clientUuid),
            'end_break' => $this->punch->endBreak($session, $photoBytes, $extension, $at, $clientUuid),
            'clock_out' => $this->punch->clockOut($session, $photoBytes, $extension, $at, $clientUuid),
        };

        /*
         * A punch whose time the client reported is labelled and flagged.
         *
         * Both marks matter for different readers: `is_offline_sync` is what the timesheet and
         * pay code filter on, while the anomaly is what a manager sees in the review queue.
         * Setting only one would leave the other audience unable to tell a client-reported
         * time from one the server witnessed.
         */
        if ($isOffline && $entry !== null) {
            $entry->forceFill(['is_offline_sync' => true])->save();
            $this->offline->markSynced($entry, $at, $serverTime);
        }

        /*
         * Every successful action is trailed, so "the system lost my clock-out" can be
         * answered with the moment it was recorded, from which device, against which
         * code.
         *
         * This row is ALSO the idempotency ledger when the client supplied an id: it is what a
         * retry will match against, for actions that close a segment as well as ones that open
         * one.
         */
        PunchEvent::record(
            $isOffline ? PunchEventType::OFFLINE_SYNC : PunchEventType::forAction($validated['action']),
            employeeId: $session->employee_id,
            outletId: $session->outlet_id,
            tokenId: $session->outlet_token_id,
            timeEntryId: $entry?->id,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            meta: [
                'device' => $session->device,
                'photo' => $photoBytes !== null,
                // Recorded so the trail shows what was claimed alongside what was stored.
                'claimed_at' => $at->toIso8601String(),
                'recorded_at' => $serverTime->toIso8601String(),
            ],
            clientUuid: $clientUuid,
        );

        return ApiResponse::success([
            'state' => $this->state->for($session->fresh(['employee', 'outlet'])),
            'entry_id' => $entry?->id,
            'offline' => $isOffline,
        ], $this->confirmation($validated['action']));
    }

    /** The employee's own hours for this week — the one screen staff may see. */
    public function myHours(Request $request): JsonResponse
    {
        $session = $this->sessionFrom($request);

        if ($session === null) {
            return ApiResponse::error(
                'Your session has expired. Scan the code again.',
                null,
                401,
                'SESSION_EXPIRED',
            );
        }

        return ApiResponse::success($this->state->weeklySummary($session->employee));
    }

    // ---- Internals ---------------------------------------------------

    private function sessionFrom(Request $request): ?PunchSession
    {
        $token = $request->header('X-Punch-Session');

        if (blank($token)) {
            return null;
        }

        return $this->punch->resolveSession((string) $token);
    }

    /**
     * Decode a data URL or bare base64 photo.
     *
     * @return array{0: ?string, 1: string}
     */
    private function decodePhoto(?string $encoded): array
    {
        if (blank($encoded)) {
            return [null, 'jpg'];
        }

        $extension = 'jpg';

        if (str_contains($encoded, 'data:image/png')) {
            $extension = 'png';
        } elseif (str_contains($encoded, 'data:image/webp')) {
            $extension = 'webp';
        }

        // Strip the data URL prefix if present.
        if (str_contains($encoded, ',')) {
            $encoded = substr($encoded, strpos($encoded, ',') + 1);
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            return [null, $extension];
        }

        /*
         * A cap on size. The bytes are written straight to disk, so an unbounded upload
         * would let a phone fill the disk from the punch screen.
         */
        if (strlen($bytes) > 3 * 1024 * 1024) {
            return [null, $extension];
        }

        return [$bytes, $extension];
    }

    private function confirmation(string $action): string
    {
        return match ($action) {
            'clock_in' => 'Clocked in. Have a good shift.',
            'start_break' => 'Break started. Your break is not paid.',
            'end_break' => 'Back to work.',
            'clock_out' => 'Clocked out. Your hours are recorded.',
        };
    }

    private function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }

    /** Coarse device class, for anomaly detection only. */
    private function deviceClass(Request $request): string
    {
        $agent = (string) $request->userAgent();

        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            $agent === '' => 'unknown',
            default => 'web',
        };
    }
}
