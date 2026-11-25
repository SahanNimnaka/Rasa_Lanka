<?php

namespace App\Http\Controllers\Api\V1\AnnotationRegistries;

use App\Http\Controllers\Api\V1\Controller;
use App\Services\Annotation\Registries\LabelMappingDecisionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelMappingDecisionController extends Controller
{
    public function __construct(private readonly LabelMappingDecisionRegistry $registry)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->can('annotation.access')) {
            return self::error('FORBIDDEN', 'You are not authorized to perform this action.', 403);
        }

        try {
            $data = $this->registry->get();
        } catch (\Throwable) {
            return self::error('REGISTRY_UNAVAILABLE', 'The label mapping decision registry is unavailable or invalid.', 503);
        }

        return self::success([
            'version' => $data['version'],
            'generated_at' => $data['generated_at'],
            'scope' => $data['scope'],
            'policy' => $data['policy'],
            'decisions' => $data['decisions'],
        ], ['registry' => 'label_mapping_decisions']);
    }
}
