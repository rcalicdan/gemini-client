<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Application-level cache for Gemini API responses
 */
class GeminiCache
{
    private CacheInterface $cache;
    private bool $enabled;
    private int $defaultTtl;
    private static ?string $defaultCachePath = null;

    public function __construct(
        ?CacheInterface $cache = null, 
        int $defaultTtl = 3600,
        ?string $cachePath = null
    ) {
        $this->cache = $cache ?? $this->createDefaultCache($cachePath);
        $this->enabled = true;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Set the default cache path for all instances
     */
    public static function setDefaultCachePath(string $path): void
    {
        self::$defaultCachePath = $path;
    }

    /**
     * Get the default cache path
     */
    public static function getDefaultCachePath(): string
    {
        return self::$defaultCachePath ?? sys_get_temp_dir() . '/gemini-client-cache';
    }

    /**
     * Generate cache key from URL and payload
     */
    public function generateKey(string $url, array $payload): string
    {
        return 'gemini_' . md5($url . json_encode($payload));
    }

    /**
     * Get cached response
     */
    public function get(string $key): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $data = $this->cache->get($key);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Store response in cache
     */
    public function set(string $key, Response $response, int $ttl): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->cache->set($key, [
                'body' => (string) $response->getBody(),
                'status' => $response->status(),
                'headers' => $response->headers(),
            ], $ttl);
        } catch (\Throwable $e) {
            // Silently fail on cache write errors
        }
    }

    /**
     * Clear all cached items
     */
    public function clear(): void
    {
        try {
            $this->cache->clear();
        } catch (\Throwable $e) {
            // Silently fail on cache clear errors
        }
    }

    /**
     * Delete a specific cached item
     */
    public function delete(string $key): void
    {
        try {
            $this->cache->delete($key);
        } catch (\Throwable $e) {
            // Silently fail on cache delete errors
        }
    }

    /**
     * Create default file-based cache
     */
    private function createDefaultCache(?string $cachePath = null): CacheInterface
    {
        $cacheDir = $cachePath ?? self::getDefaultCachePath();
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $adapter = new FilesystemAdapter('gemini', 0, $cacheDir);
        return new Psr16Cache($adapter);
    }
}