<?php

namespace App\Services\Annotation\Registries;

use RuntimeException;

class ApprovedTaxonomyRegistry
{
    public function __construct(
        private readonly RegistryPathResolver $paths,
        private readonly RegistryFileCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $path = $this->paths->taxonomyPath();
        return $this->cache->remember($path, 'annotation.registry.taxonomy', function (string $path): array {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Taxonomy registry is invalid.');
            }

            foreach (['taxonomy_version', 'source_schema', 'generated_at', 'in_scope_classes', 'out_of_scope_classes', 'processing_states'] as $key) {
                if (! array_key_exists($key, $decoded)) {
                    throw new RuntimeException('Taxonomy registry is invalid.');
                }
            }

            $inScope = $this->stringList($decoded['in_scope_classes'], 'in_scope_classes');
            $outScope = $this->stringList($decoded['out_of_scope_classes'], 'out_of_scope_classes');
            $states = $this->stringList($decoded['processing_states'], 'processing_states');

            $all = array_merge($inScope, $outScope);
            if (count($all) !== count(array_unique($all))) {
                throw new RuntimeException('Taxonomy registry is inconsistent.');
            }

            return [
                'taxonomy_version' => $this->stringValue($decoded['taxonomy_version'], 'taxonomy_version'),
                'source_schema' => $this->stringValue($decoded['source_schema'], 'source_schema'),
                'generated_at' => $this->stringValue($decoded['generated_at'], 'generated_at'),
                'in_scope_classes' => $inScope,
                'out_of_scope_classes' => $outScope,
                'processing_states' => $states,
                'in_scope_count' => count($inScope),
                'out_of_scope_count' => count($outScope),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $t = $this->get();
        return [
            'taxonomy_version' => $t['taxonomy_version'],
            'source_schema' => $t['source_schema'],
            'generated_at' => $t['generated_at'],
            'in_scope_count' => $t['in_scope_count'],
            'out_of_scope_count' => $t['out_of_scope_count'],
            'processing_state_count' => count($t['processing_states']),
        ];
    }

    /** @param mixed $value @return list<string> */
    private function stringList($value, string $field): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Taxonomy registry is invalid.');
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new RuntimeException('Taxonomy registry is invalid.');
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @param mixed $value */
    private function stringValue($value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Taxonomy registry is invalid.');
        }
        return $value;
    }
}
