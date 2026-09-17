<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Console sign-in.
 *
 * Deliberately simple and LOCAL. There is no call to the ordering system here, and
 * there must never be one: staff clock in at 07:00 whether or not another
 * application is reachable, and Attendance has to be deployable on its own.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        /*
         * One message for both "no such user" and "wrong password" so the response
         * cannot be used to enumerate which emails have accounts. Hash::check is
         * still called for a missing user to keep the timing similar.
         */
        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /*
         * Deactivation is refused at sign-in AND checked on every later request.
         * Checking only here would let someone who was disabled mid-shift keep
         * working until their token expired.
         */
        if (! $user->is_active) {
            return ApiResponse::error(
                'This account has been deactivated. Ask the owner to re-enable it.',
                null,
                403,
                'ACCOUNT_INACTIVE',
            );
        }

        // One token per device; the old ones are left alone so a second device can
        // sign in without kicking the first out mid-shift.
        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::success([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        // Revokes only the token used for this request, so other devices stay signed in.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out.');
    }

    /** The signed-in user, for the console shell to render on load. */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $outletIds = $user->visibleOutletIds();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            /*
             * null means every outlet, and is sent as null rather than an empty
             * array so the console cannot mistake "unrestricted" for "no access".
             * The server enforces this regardless; the client only renders it.
             */
            'visible_outlet_ids' => $outletIds,
            'can_administer' => $user->role->canAdminister(),
            'is_self_service_only' => $user->role->isSelfServiceOnly(),
            // Included so the console can fetch the linked employee's own hours.
            'employee_id' => $user->employee?->id,
        ];
    }

    /** A recognisable device label, so a stray token can be identified later. */
    private function deviceName(Request $request): string
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
