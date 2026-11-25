<?php

namespace App\Http\Controllers\Api\V1\AnnotationRegistries;

use App\Http\Controllers\Api\V1\Controller;
use App\Http\Requests\Api\V1\AnnotationRegistries\ImageIdLookupRequest;
use App\Services\Annotation\Registries\ImageIdRegistry;
use App\Services\Annotation\Registries\MasterImageInventoryRegistry;
use App\Services\Annotation\Registries\RegistryHealthService;
use Illuminate\Http\JsonResponse;

class ImageIdLookupController extends Controller
{
    public function __construct(
        private readonly ImageIdRegistry $imageIds,
        private readonly MasterImageInventoryRegistry $inventory,
        private readonly RegistryHealthService $health,
    ) {
    }

    public function show(ImageIdLookupRequest $request): JsonResponse
    {
        try {
            $this->health->assertConsistency();
        } catch (\Throwable) {
            return self::error('REGISTRY_UNAVAILABLE', 'Registry consistency cannot be established.', 503);
        }

        $imageId = $request->input('image_id');
        $relativePath = $request->input('relative_path');

        try {
            if (is_string($imageId) && $imageId !== '') {
                $resolvedPath = $this->imageIds->findByImageId($imageId);
                $inventoryRow = $this->inventory->findByImageId($imageId);
            } else {
                $resolvedId = $this->imageIds->findByRelativePath((string) $relativePath);
                $inventoryRow = $this->inventory->findByRelativePath((string) $relativePath);
                $imageId = $resolvedId;
                $resolvedPath = is_string($relativePath) ? $relativePath : null;
            }
        } catch (\Throwable) {
            return self::error('REGISTRY_UNAVAILABLE', 'The image ID registry is unavailable or invalid.', 503);
        }

        $registryFound = is_string($imageId) && is_string($resolvedPath);
        $inventoryFound = is_array($inventoryRow);

        if (! $registryFound && ! $inventoryFound) {
            return self::error('NOT_FOUND', 'The requested registry record was not found.', 404);
        }

        if ($registryFound && $inventoryFound) {
            if ($inventoryRow['image_id'] !== $imageId || $inventoryRow['relative_path'] !== $resolvedPath) {
                return self::error('REGISTRY_UNAVAILABLE', 'Registry consistency cannot be established.', 503);
            }
        }

        // If present in one but not the other, treat as controlled inconsistency.
        if ($registryFound xor $inventoryFound) {
            return self::error('REGISTRY_UNAVAILABLE', 'Registry consistency cannot be established.', 503);
        }

        return self::success([
            'image_id' => $imageId,
            'relative_path' => $resolvedPath,
            'inventory' => $inventoryRow,
        ], ['registry' => 'image_id_lookup']);
    }
}
