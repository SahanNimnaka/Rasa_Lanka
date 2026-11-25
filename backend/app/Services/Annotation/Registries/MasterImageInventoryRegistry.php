<?php

namespace App\Services\Annotation\Registries;

use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class MasterImageInventoryRegistry
{
    private const REQUIRED_COLUMNS = [
        'image_id','batch_id','original_folder','original_filename','relative_path','file_extension','file_size_bytes','image_width','image_height','image_mode','image_format','sha256','folder_class_proposal','mapping_status','scan_status','scan_timestamp','first_seen_timestamp','error_message','duplicate_group_id','duplicate_of_image_id','is_exact_duplicate'
    ];

    public function __construct(
        private readonly RegistryPathResolver $paths,
        private readonly RegistryFileCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $path = $this->paths->inventoryPath();
        return $this->cache->remember($path, 'annotation.registry.inventory', function (string $path): array {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Image inventory is unavailable.');
            }

            try {
                $header = fgetcsv($handle);
                if (! is_array($header) || $header !== self::REQUIRED_COLUMNS) {
                    throw new RuntimeException('Image inventory is invalid.');
                }

                $rows = [];
                $byId = [];
                $byPath = [];
                $folders = [];
                $mappingStatus = [];
                $scanStatus = [];

                while (($row = fgetcsv($handle)) !== false) {
                    if ($row === [null] || $row === false) continue;
                    if (count($row) !== count(self::REQUIRED_COLUMNS)) {
                        throw new RuntimeException('Image inventory is invalid.');
                    }
                    $assoc = array_combine(self::REQUIRED_COLUMNS, $row);
                    if (! is_array($assoc)) {
                        throw new RuntimeException('Image inventory is invalid.');
                    }

                    $imageId = $this->requiredString($assoc['image_id']);
                    $relativePath = $this->requiredString($assoc['relative_path']);
                    $folder = $this->requiredString($assoc['original_folder']);
                    $filename = $this->requiredString($assoc['original_filename']);
                    $expected = $folder.'/'.$filename;
                    if ($relativePath !== $expected) {
                        throw new RuntimeException('Image inventory is inconsistent.');
                    }
                    if (isset($byId[$imageId]) || isset($byPath[$relativePath])) {
                        throw new RuntimeException('Image inventory is inconsistent.');
                    }

                    $normalized = [
                        'image_id' => $imageId,
                        'batch_id' => $assoc['batch_id'],
                        'original_folder' => $folder,
                        'original_filename' => $filename,
                        'relative_path' => $relativePath,
                        'file_extension' => $assoc['file_extension'],
                        'file_size_bytes' => $assoc['file_size_bytes'],
                        'image_width' => $assoc['image_width'],
                        'image_height' => $assoc['image_height'],
                        'image_mode' => $assoc['image_mode'],
                        'image_format' => $assoc['image_format'],
                        'sha256' => $assoc['sha256'],
                        'folder_class_proposal' => $assoc['folder_class_proposal'],
                        'mapping_status' => $assoc['mapping_status'],
                        'scan_status' => $assoc['scan_status'],
                        'scan_timestamp' => $assoc['scan_timestamp'],
                        'first_seen_timestamp' => $assoc['first_seen_timestamp'],
                        'error_message' => $assoc['error_message'],
                        'duplicate_group_id' => $assoc['duplicate_group_id'],
                        'duplicate_of_image_id' => $assoc['duplicate_of_image_id'],
                        'is_exact_duplicate' => $assoc['is_exact_duplicate'],
                    ];

                    $index = count($rows);
                    $rows[] = $normalized;
                    $byId[$imageId] = $index;
                    $byPath[$relativePath] = $index;
                    $folders[$folder] = ($folders[$folder] ?? 0) + 1;
                    $mappingStatus[$assoc['mapping_status']] = ($mappingStatus[$assoc['mapping_status']] ?? 0) + 1;
                    $scanStatus[$assoc['scan_status']] = ($scanStatus[$assoc['scan_status']] ?? 0) + 1;
                }

                ksort($folders);
                ksort($mappingStatus);
                ksort($scanStatus);

                return [
                    'rows' => $rows,
                    'by_id' => $byId,
                    'by_path' => $byPath,
                    'count' => count($rows),
                    'folders' => $folders,
                    'mapping_status_counts' => $mappingStatus,
                    'scan_status_counts' => $scanStatus,
                ];
            } finally {
                fclose($handle);
            }
        });
    }

    /** @return array<string,mixed>|null */
    public function findByImageId(string $imageId): ?array
    {
        $inv = $this->get();
        $idx = $inv['by_id'][$imageId] ?? null;
        return is_int($idx) ? $inv['rows'][$idx] : null;
    }

    /** @return array<string,mixed>|null */
    public function findByRelativePath(string $relativePath): ?array
    {
        $inv = $this->get();
        $idx = $inv['by_path'][$relativePath] ?? null;
        return is_int($idx) ? $inv['rows'][$idx] : null;
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $inv = $this->get();
        $filtered = [];
        foreach ($inv['rows'] as $row) {
            if (isset($filters['image_id']) && $row['image_id'] !== $filters['image_id']) continue;
            if (isset($filters['relative_path']) && $row['relative_path'] !== $filters['relative_path']) continue;
            if (isset($filters['original_folder']) && $row['original_folder'] !== $filters['original_folder']) continue;
            if (isset($filters['mapping_status']) && $row['mapping_status'] !== $filters['mapping_status']) continue;
            if (isset($filters['scan_status']) && $row['scan_status'] !== $filters['scan_status']) continue;
            $filtered[] = $row;
        }

        $total = count($filtered);
        $offset = max(0, ($page - 1) * $perPage);
        $items = array_slice($filtered, $offset, $perPage);

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $inv = $this->get();
        return [
            'total_images' => $inv['count'],
            'folder_count' => count($inv['folders']),
            'folders' => array_keys($inv['folders']),
            'mapping_status_counts' => $inv['mapping_status_counts'],
            'scan_status_counts' => $inv['scan_status_counts'],
        ];
    }

    /** @param mixed $value */
    private function requiredString($value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Image inventory is invalid.');
        }
        return $value;
    }
}
