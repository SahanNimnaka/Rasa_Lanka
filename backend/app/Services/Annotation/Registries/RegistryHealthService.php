<?php

namespace App\Services\Annotation\Registries;

use RuntimeException;

class RegistryHealthService
{
    public function __construct(
        private readonly RegistryPathResolver $paths,
        private readonly ApprovedTaxonomyRegistry $taxonomy,
        private readonly LabelMappingDecisionRegistry $decisions,
        private readonly ImageIdRegistry $imageIds,
        private readonly MasterImageInventoryRegistry $inventory,
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $errors = [];
        $taxonomy = $decisions = $imageIds = $inventory = null;

        try { $taxonomy = $this->taxonomy->summary(); } catch (\Throwable) { $errors[] = 'taxonomy_unavailable'; }
        try { $decisions = $this->decisions->summary(); } catch (\Throwable) { $errors[] = 'decisions_unavailable'; }
        try { $imageIds = $this->imageIds->summary(); } catch (\Throwable) { $errors[] = 'image_id_registry_unavailable'; }
        try { $inventory = $this->inventory->summary(); } catch (\Throwable) { $errors[] = 'inventory_unavailable'; }

        $consistent = false;
        $consistencyErrors = [];
        if (empty($errors)) {
            try {
                $this->assertConsistency();
                $consistent = true;
            } catch (\Throwable) {
                $consistencyErrors[] = 'registry_inconsistent';
            }
        }

        $status = empty($errors) && $consistent ? 'ok' : 'degraded';

        return [
            'status' => $status,
            'registries' => [
                'taxonomy' => ['available' => $taxonomy !== null, 'summary' => $taxonomy],
                'label_mapping_decisions' => ['available' => $decisions !== null, 'summary' => $decisions],
                'image_id_registry' => ['available' => $imageIds !== null, 'summary' => $imageIds],
                'image_inventory' => ['available' => $inventory !== null, 'summary' => $inventory],
            ],
            'consistency' => [
                'consistent' => $consistent,
                'errors' => $consistencyErrors,
            ],
            'errors' => $errors,
            'notes' => [
                'metadata_only' => true,
                'image_binaries_served' => false,
                'canonical_inventory_source' => 'master_image_inventory.csv',
            ],
        ];
    }

    public function assertConsistency(): void
    {
        $imageReg = $this->imageIds->get();
        $inv = $this->inventory->get();

        if ($imageReg['entry_count'] !== $inv['count']) {
            throw new RuntimeException('Registry count mismatch.');
        }
        foreach ($inv['rows'] as $row) {
            $id = $row['image_id'];
            $path = $row['relative_path'];
            if (($imageReg['id_to_path'][$id] ?? null) !== $path) {
                throw new RuntimeException('Registry mapping mismatch.');
            }
            if (($imageReg['path_to_id'][$path] ?? null) !== $id) {
                throw new RuntimeException('Registry mapping mismatch.');
            }
        }
    }
}
