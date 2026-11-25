<?php

namespace App\Services\Annotation\Registries;

use RuntimeException;

class RegistryPathResolver
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('annotation');
    }

    public function taxonomyPath(): string { return $this->resolve('registry_taxonomy_path', 'b1_taxonomy_path'); }
    public function inventoryPath(): string { return $this->resolve('registry_inventory_path', 'b1_inventory_path'); }
    public function decisionsPath(): string { return $this->resolve('registry_decisions_path', 'b1_decisions_path'); }
    public function imageIdRegistryPath(): string { return $this->resolve('registry_image_id_registry_path'); }

    private function resolve(string $key, ?string $fallbackKey = null): string
    {
        $value = $this->config[$key] ?? ($fallbackKey ? ($this->config[$fallbackKey] ?? '') : '');
        $path = is_string($value) ? trim($value) : '';
        if ($path === '') {
            throw new RuntimeException('Registry path is not configured.');
        }

        $candidate = $this->normalizeConfiguredPath($path);
        $this->assertNoSymlinkSegments($candidate);

        $real = realpath($candidate);
        if ($real === false) {
            throw new RuntimeException('Registry file is unavailable.');
        }

        // Defense in depth: ensure resolved target is not a symlink and no segment on the resolved path is a symlink.
        $this->assertNoSymlinkSegments($real);

        if (! is_file($real) || is_link($real) || ! is_readable($real)) {
            throw new RuntimeException('Registry file is unavailable.');
        }

        return $real;
    }

    private function normalizeConfiguredPath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if (! $this->isAbsolutePath($path)) {
            $workdir = $this->config['b1_workdir'] ?? base_path('..');
            $base = is_string($workdir) && trim($workdir) !== '' ? trim($workdir) : base_path('..');
            $base = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);
            if (! $this->isAbsolutePath($base)) {
                $base = base_path($base);
            }
            $path = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR);
        }

        $normalized = $this->normalizeAbsolutePath($path);
        if ($normalized === null) {
            throw new RuntimeException('Registry file is unavailable.');
        }

        return $normalized;
    }

    private function normalizeAbsolutePath(string $path): ?string
    {
        $prefix = '';
        $remainder = $path;

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            $prefix = strtoupper($path[0]).':'.DIRECTORY_SEPARATOR;
            $remainder = substr($path, 2);
        } elseif (str_starts_with($path, '\\\\')) {
            $prefix = '\\\\';
            $remainder = substr($path, 2);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $remainder = substr($path, 1);
        } else {
            return null;
        }

        $segments = preg_split('/[\\\\\\/]+/', $remainder) ?: [];
        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack === []) {
                    return null;
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        return $prefix.implode(DIRECTORY_SEPARATOR, $stack);
    }

    private function assertNoSymlinkSegments(string $path): void
    {
        $prefix = '';
        $remainder = $path;

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            $prefix = strtoupper($path[0]).':';
            $remainder = substr($path, 2);
        } elseif (str_starts_with($path, '\\\\')) {
            $parts = preg_split('/[\\\\\\/]+/', substr($path, 2), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) {
                throw new RuntimeException('Registry file is unavailable.');
            }
            $prefix = '\\\\'.$parts[0].DIRECTORY_SEPARATOR.$parts[1];
            $remainder = implode(DIRECTORY_SEPARATOR, array_slice($parts, 2));
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $remainder = substr($path, 1);
        } else {
            throw new RuntimeException('Registry file is unavailable.');
        }

        $segments = preg_split('/[\\\\\\/]+/', $remainder, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $current = rtrim($prefix, DIRECTORY_SEPARATOR);
        if ($current === '' || preg_match('/^[A-Za-z]:$/', $current) === 1) {
            $current .= DIRECTORY_SEPARATOR;
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('Registry file is unavailable.');
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new RuntimeException('Registry file is unavailable.');
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }
}
