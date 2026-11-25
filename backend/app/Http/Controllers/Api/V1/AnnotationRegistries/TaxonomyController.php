<?php

namespace App\Http\Controllers\Api\V1\AnnotationRegistries;

use App\Http\Controllers\Api\V1\Controller;
use App\Services\Annotation\Registries\ApprovedTaxonomyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomyController extends Controller
{
    public function __construct(private readonly ApprovedTaxonomyRegistry $registry)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->can('annotation.access')) {
            return self::error('FORBIDDEN', 'You are not authorized to perform this action.', 403);
        }

        try {
            $data = $this->registry->get();
        } catch (\Throwable) {
            return self::error('REGISTRY_UNAVAILABLE', 'The taxonomy registry is unavailable or invalid.', 503);
        }

        return self::success([
            'taxonomy_version' => $data['taxonomy_version'],
            'source_schema' => $data['source_schema'],
            'generated_at' => $data['generated_at'],
            'in_scope_classes' => $data['in_scope_classes'],
            'out_of_scope_classes' => $data['out_of_scope_classes'],
            'processing_states' => $data['processing_states'],
        ], ['registry' => 'approved_taxonomy']);
    }
}
