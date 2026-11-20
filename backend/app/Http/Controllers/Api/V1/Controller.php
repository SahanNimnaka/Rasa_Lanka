<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

abstract class Controller extends BaseController
{
    public const API_VERSION = 'v1';

    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => array_merge($meta, [
                'api_version' => self::API_VERSION,
            ]),
        ], $status);
    }

    public static function paginated(
        LengthAwarePaginator $paginator,
        mixed $data = null,
        array $meta = [],
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $data ?? $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => array_merge($meta, [
                'api_version' => self::API_VERSION,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]),
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        mixed $details = null,
        array $meta = []
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
            'meta' => array_merge($meta, [
                'api_version' => self::API_VERSION,
            ]),
        ], $status);
    }

    public static function validationFailed(ValidationException $exception): JsonResponse
    {
        return self::error(
            'VALIDATION_FAILED',
            'The given data was invalid.',
            422,
            $exception->errors()
        );
    }
}
