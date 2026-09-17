<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OutletTokenMode;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\OutletToken;
use App\Services\OutletTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Outlets and their punch codes.
 *
 * The owner sees and manages every outlet; a manager sees only theirs, and can
 * reprint a code for their own outlet because reprinting is the revoke mechanism —
 * making that awkward would leave a leaked sheet live indefinitely.
 */
class AdminOutletController extends Controller
{
    public function __construct(private readonly OutletTokenService $tokens) {}

    public function index(Request $request): JsonResponse
    {
        $outlets = Outlet::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            /*
             * Filtered in the query rather than by a policy check per row, because
             * the correct behaviour for a manager is to see a SHORTER LIST, not a
             * list of rows they are forbidden from opening.
             */
            ->filter(fn (Outlet $outlet) => $request->user()->canAccessOutlet($outlet->id))
            ->values();

        return ApiResponse::success([
            'outlets' => $outlets->map(fn (Outlet $outlet) => $this->outletPayload($outlet)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', 'unique:outlets,code'],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'token_mode' => ['sometimes', new Enum(OutletTokenMode::class)],
            'qr_ttl_seconds' => ['sometimes', 'integer', 'min:30', 'max:600'],
            'requires_photo' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $outlet = Outlet::create($validated);

        return ApiResponse::created(
            $this->outletPayload($outlet),
            'Outlet added.',
        );
    }

    public function update(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorizeOutlet($request, $outlet);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'code' => ['sometimes', 'string', 'max:20', 'alpha_dash', Rule::unique('outlets', 'code')->ignore($outlet->id)],
            /*
             * Changing the mode takes effect on the next issue. A manager or the
             * owner must reprint afterwards, which is deliberate: silently swapping
             * between a printed sheet and a rotating device would invalidate one of
             * them without anyone realising until someone could not clock in.
             */
            'token_mode' => ['sometimes', new Enum(OutletTokenMode::class)],
            'qr_ttl_seconds' => ['sometimes', 'integer', 'min:30', 'max:600'],
            'requires_photo' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $outlet->update($validated);

        return ApiResponse::success($this->outletPayload($outlet->fresh()), 'Outlet updated.');
    }

    /** The code currently in force, if any. */
    public function currentToken(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorizeOutlet($request, $outlet);

        $token = $this->tokens->currentFor($outlet);

        return ApiResponse::success([
            'outlet' => $this->outletPayload($outlet),
            'token' => $token === null ? null : $this->tokenPayload($token),
        ]);
    }

    /**
     * Issue a replacement code, revoking the previous one.
     *
     * This is the reprint action, and it is the ONLY way to kill a leaked sheet, so
     * it is deliberately a single call with no confirmation step beyond the UI's own.
     */
    public function regenerateToken(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorizeOutlet($request, $outlet);

        $token = $this->tokens->regenerate($outlet, $request->user());

        return ApiResponse::success([
            'token' => $this->tokenPayload($token),
            'message' => 'New code issued. Any previous code for this outlet has been revoked.',
        ], 'Code replaced.');
    }

    /** Revoke every live code, leaving the outlet punchable by nobody. */
    public function revokeToken(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorizeOutlet($request, $outlet);

        $count = $this->tokens->revokeLive($outlet, $request->user());

        return ApiResponse::success([
            'revoked' => $count,
        ], $count === 0
            ? 'There was no live code to revoke.'
            : "Revoked {$count} code(s). Nobody can clock in at this outlet until a new one is issued.");
    }

    /**
     * The printable sheet.
     *
     * Returns the payload rather than an image: the console renders it as a print
     * view, which keeps the QR generation in one place and avoids storing a
     * generated file per reprint.
     */
    public function printSheet(Request $request, Outlet $outlet): JsonResponse
    {
        $this->authorizeOutlet($request, $outlet);

        $token = $this->tokens->currentFor($outlet);

        if ($token === null) {
            return ApiResponse::error(
                'This outlet has no active code. Issue one before printing.',
                null,
                409,
                'NO_ACTIVE_TOKEN',
            );
        }

        return ApiResponse::success([
            'outlet' => $this->outletPayload($outlet),
            'token' => $this->tokenPayload($token),
            // What the sheet should say, so the wording lives on the server and the
            // console does not invent its own instructions.
            'instructions' => $outlet->token_mode->expires()
                ? 'Display this code on the outlet device. It refreshes automatically.'
                : 'Print and display at the counter. If this sheet is photographed or lost, reprint to revoke it.',
            'scan_url' => url('/punch?t='.$token->token),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outletPayload(Outlet $outlet): array
    {
        $current = $this->tokens->currentFor($outlet);

        return [
            'id' => $outlet->id,
            'code' => $outlet->code,
            'name' => $outlet->name,
            'address' => $outlet->address,
            'timezone' => $outlet->timezone,
            'token_mode' => $outlet->token_mode->value,
            'token_mode_label' => $outlet->token_mode->label(),
            'qr_ttl_seconds' => $outlet->qr_ttl_seconds,
            'requires_photo' => $outlet->requires_photo,
            'is_active' => $outlet->is_active,
            'employees_count' => $outlet->employees_count ?? null,
            // Whether a code exists, so the console can prompt to issue one.
            'has_live_token' => $current !== null,
            'current_token_issued_at' => $current?->created_at?->toIso8601String(),
            'current_token_generations' => $current?->generations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(OutletToken $token): array
    {
        return [
            'id' => $token->id,
            'token' => $token->token,
            'mode' => $token->mode->value,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'generations' => $token->generations,
            'issued_at' => $token->created_at?->toIso8601String(),
        ];
    }

    /** Only the owner adds or reconfigures an outlet. */
    private function authorizeOwner(Request $request): void
    {
        if (! $request->user()->isOwner()) {
            abort(403, 'Only the owner can add an outlet.');
        }
    }

    /** Managers may act on their own outlets; the owner on any. */
    private function authorizeOutlet(Request $request, Outlet $outlet): void
    {
        if (! $request->user()->canAccessOutlet($outlet->id)) {
            // 404, not 403: a manager probing for another outlet should not learn
            // whether it exists.
            abort(404);
        }
    }
}
