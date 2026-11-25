<?php

namespace App\Http\Controllers\Api\V1\AnnotationRegistries;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\AnnotationRegistries\ImageInventoryIndexRequest;
use App\Services\Annotation\Registries\MasterImageInventoryRegistry;
use Illuminate\Http\JsonResponse;

class ImageInventoryController extends Controller
{
    public function __construct(private readonly MasterImageInventoryRegistry $registry)
    {
    }

    public function index(ImageInventoryIndexRequest $request): JsonResponse
    {
        $filters = array_filter($request->only([
            'image_id', 'relative_path', 'original_folder', 'mapping_status', 'scan_status',
        ]), static fn ($v) => $v !== null && $v !== '');

        $perPage = (int) $request->integer('per_page', (int) config('annotation.registry_inventory_default_per_page', 25));
        $max = (int) config('annotation.registry_inventory_max_per_page', 100);
        $perPage = max(1, min($max, $perPage));
        $page = max(1, (int) $request->integer('page', 1));

        try {
            $paginator = $this->registry->paginate($filters, $perPage, $page);
        } catch (\Throwable) {
            return self::error('REGISTRY_UNAVAILABLE', 'The image inventory registry is unavailable or invalid.', 503);
        }

        return self::paginated($paginator, $paginator->items(), ['registry' => 'master_image_inventory']);
    }
}
