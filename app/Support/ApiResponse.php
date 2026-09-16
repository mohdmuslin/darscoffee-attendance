<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Uniform JSON envelope for every API response:
 * { success, data, message, errors }
 *
 * Carried over from the ordering system, where having one shape everywhere meant
 * clients parsed one thing and never had to guess. Consistency here is worth more
 * than tailoring each endpoint.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $extra  Merged alongside `data` for
     *                                       response-level metadata such as
     *                                       signed URLs.
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => null,
            ...$extra,
        ], $status);
    }

    public static function created(mixed $data = null, ?string $message = 'Created.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = 400, ?string $code = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
        ], $status);
    }

    public static function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return self::error($message, null, 404, 'NOT_FOUND');
    }

    /**
     * Used for an out-of-scope outlet.
     *
     * Returns 404 rather than 403 on purpose: a manager probing for another
     * outlet's records should not learn whether that outlet exists. The ordering
     * system made the same choice for another table's order.
     */
    public static function forbidden(string $message = 'You are not allowed to perform this action.'): JsonResponse
    {
        return self::error($message, null, 403, 'FORBIDDEN');
    }
}
