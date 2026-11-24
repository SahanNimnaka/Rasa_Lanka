<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Annotation\B1ValidationAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class B1RuntimeHealthController extends Controller
{
    public function __construct(private readonly B1ValidationAdapter $adapter)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isResearchAdmin()) {
            return self::error('FORBIDDEN', 'You are not authorized to perform this action.', 403);
        }

        $health = $this->adapter->healthCheck();
        $status = $health['ok'] ? 200 : 503;
        return self::success($health, [], $status);
    }
}
