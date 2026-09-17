<?php

namespace App\Http\Controllers\Api\V1\Punch;

use App\Http\Controllers\Controller;
use App\Models\PunchSession;
use App\Services\PhotoService;
use App\Services\PunchService;
use App\Services\PunchStateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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

        [$photoBytes, $extension] = $this->decodePhoto($validated['photo'] ?? null);

        /*
         * A photo is required when the outlet asks for one. Enforced here rather than
         * only warned about, because a punch without a photo at an outlet that requires
         * them leaves nothing to settle a dispute with — which is the whole point.
         */
        if ($session->outlet->requires_photo && $photoBytes === null) {
            return ApiResponse::error(
                'This outlet needs a photo with every punch. Allow camera access and try again.',
                null,
                422,
                'PHOTO_REQUIRED',
            );
        }

        $entry = match ($validated['action']) {
            'clock_in' => $this->punch->clockIn($session, $photoBytes, $extension),
            'start_break' => $this->punch->startBreak($session, $photoBytes, $extension),
            'end_break' => $this->punch->endBreak($session, $photoBytes, $extension),
            'clock_out' => $this->punch->clockOut($session, $photoBytes, $extension),
        };

        return ApiResponse::success([
            'state' => $this->state->for($session->fresh(['employee', 'outlet'])),
            'entry_id' => $entry?->id,
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
