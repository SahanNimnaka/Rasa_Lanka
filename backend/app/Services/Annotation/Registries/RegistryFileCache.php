<?php

namespace App\Services\Annotation\Registries;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RegistryFileCache
{
    public function __construct(private readonly ?int $ttlSeconds = null)
    {
    }

    /**
     * @template T
     * @param  callable(string):T  $reader
     * @return T
     */
    public function remember(string $path, string $keyPrefix, callable $reader): mixed
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false) {
            throw new RuntimeException('Registry file metadata unavailable.');
        }
        $cacheKey = $keyPrefix.':'.sha1($path.'|'.$mtime.'|'.$size);
        $ttl = $this->ttlSeconds ?? (int) config('annotation.registry_cache_ttl_seconds', 30);
        $ttl = max(1, min(300, $ttl));

        return Cache::remember($cacheKey, now()->addSeconds($ttl), static fn () => $reader($path));
    }
}
