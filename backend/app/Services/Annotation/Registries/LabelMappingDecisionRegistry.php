<?php

namespace App\Services\Annotation\Registries;

use RuntimeException;

class LabelMappingDecisionRegistry
{
    public function __construct(
        private readonly RegistryPathResolver $paths,
        private readonly RegistryFileCache $cache,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $path = $this->paths->decisionsPath();
        return $this->cache->remember($path, 'annotation.registry.decisions', function (string $path): array {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Label mapping decisions registry is invalid.');
            }
            foreach (['version', 'generated_at', 'scope', 'policy', 'decisions'] as $key) {
                if (! array_key_exists($key, $decoded)) {
                    throw new RuntimeException('Label mapping decisions registry is invalid.');
                }
            }
            if (! is_array($decoded['policy']) || ! is_array($decoded['decisions'])) {
                throw new RuntimeException('Label mapping decisions registry is invalid.');
            }

            $decisions = [];
            foreach ($decoded['decisions'] as $decision) {
                if (! is_array($decision)) {
                    throw new RuntimeException('Label mapping decisions registry is invalid.');
                }
                $decisions[] = $decision;
            }

            return [
                'version' => $this->asString($decoded['version']),
                'generated_at' => $this->asString($decoded['generated_at']),
                'scope' => $this->asString($decoded['scope']),
                'policy' => $decoded['policy'],
                'decisions' => $decisions,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $d = $this->get();
        $approved = 0;
        $pending = 0;
        foreach ($d['decisions'] as $decision) {
            $type = $decision['decision_type'] ?? null;
            if ($type === 'approved_alias') $approved++;
            if (($decision['canonical_mapping_pending'] ?? false) === true || $type === 'pending_canonical_mapping') $pending++;
        }
        return [
            'version' => $d['version'],
            'generated_at' => $d['generated_at'],
            'decision_count' => count($d['decisions']),
            'approved_alias_count' => $approved,
            'pending_count' => $pending,
        ];
    }

    /** @param mixed $value */
    private function asString($value): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Label mapping decisions registry is invalid.');
        }
        return $value;
    }
}
