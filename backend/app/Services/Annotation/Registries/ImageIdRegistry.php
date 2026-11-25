<?php

namespace App\Services\Annotation\Registries;

use RuntimeException;

class ImageIdRegistry
{
    public function __construct(
        private readonly RegistryPathResolver $paths,
        private readonly RegistryFileCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $path = $this->paths->imageIdRegistryPath();
        return $this->cache->remember($path, 'annotation.registry.image_id', function (string $path): array {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Image ID registry is invalid.');
            }
            foreach (['registry_version', 'next_sequence', 'path_to_id'] as $key) {
                if (! array_key_exists($key, $decoded)) {
                    throw new RuntimeException('Image ID registry is invalid.');
                }
            }
            $pathToId = $decoded['path_to_id'];
            if (! is_array($pathToId) || ! is_int($decoded['next_sequence'])) {
                throw new RuntimeException('Image ID registry is invalid.');
            }

            $idToPath = [];
            foreach ($pathToId as $relativePath => $imageId) {
                if (! is_string($relativePath) || ! is_string($imageId) || $relativePath === '' || $imageId === '') {
                    throw new RuntimeException('Image ID registry is invalid.');
                }
                if (isset($idToPath[$imageId]) && $idToPath[$imageId] !== $relativePath) {
                    throw new RuntimeException('Image ID registry is inconsistent.');
                }
                $idToPath[$imageId] = $relativePath;
            }

            if (count($idToPath) !== count($pathToId)) {
                throw new RuntimeException('Image ID registry is inconsistent.');
            }

            return [
                'registry_version' => is_string($decoded['registry_version']) ? $decoded['registry_version'] : throw new RuntimeException('Image ID registry is invalid.'),
                'next_sequence' => $decoded['next_sequence'],
                'path_to_id' => $pathToId,
                'id_to_path' => $idToPath,
                'entry_count' => count($pathToId),
            ];
        });
    }

    public function findByImageId(string $imageId): ?string
    {
        $reg = $this->get();
        $path = $reg['id_to_path'][$imageId] ?? null;
        return is_string($path) ? $path : null;
    }

    public function findByRelativePath(string $relativePath): ?string
    {
        $reg = $this->get();
        $id = $reg['path_to_id'][$relativePath] ?? null;
        return is_string($id) ? $id : null;
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $r = $this->get();
        return [
            'registry_version' => $r['registry_version'],
            'next_sequence' => $r['next_sequence'],
            'entry_count' => $r['entry_count'],
        ];
    }
}
