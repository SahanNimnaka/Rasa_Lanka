<?php

namespace App\Http\Controllers\Api\V1\AnnotationRegistries;

use App\Http\Controllers\Api\V1\Controller;
use App\Services\Annotation\Registries\RegistryHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrySummaryController extends Controller
{
    public function __construct(private readonly RegistryHealthService $health)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->can('annotation.access')) {
            return self::error('FORBIDDEN', 'You are not authorized to perform this action.', 403);
        }

        $summary = $this->health->summary();
        $status = $summary['status'] === 'ok' ? 200 : 503;
        if ($status !== 200) {
            return self::error('REGISTRY_UNAVAILABLE', 'One or more registries are unavailable or inconsistent.', 503, [
                'summary' => $summary,
            ]);
        }

        return self::success($summary, ['registry' => 'summary']);
    }
}
