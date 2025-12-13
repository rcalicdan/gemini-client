<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Throwable;

/**
 * Internal HTTP client for making Gemini API requests
 */
/**
 * Internal HTTP client for making Gemini API requests
 */
class GeminiHttpRequest
{
    private string $apiKey;
    private array $defaultHeaders;
    private GeminiRequestBuilder $builder;
    private ?CacheConfig $cacheConfig;
    private ?GeminiCache $cache;

    public function __construct(
        string $apiKey,
        array $defaultHeaders,
        GeminiRequestBuilder $builder,
        ?CacheConfig $cacheConfig = null,
        ?string $cachePath = null
    ) {
        $this->apiKey = $apiKey;
        $this->defaultHeaders = $defaultHeaders;
        $this->builder = $builder;
        $this->cacheConfig = $cacheConfig;
        $this->cache = $cacheConfig !== null
            ? new GeminiCache(null, $cacheConfig->ttlSeconds, $cachePath)
            : null;
    }

    /**
     * Make a standard HTTP request with application-level caching.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @return PromiseInterface<Response>
     */
    public function makeRequest(string $url, array $payload): PromiseInterface
    {
        if ($this->cache !== null) {
            $cacheKey = $this->cacheConfig->cacheKey ?? $this->cache->generateKey($url, $payload);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return Promise::resolved(
                    new Response($cached['body'], $cached['status'], $cached['headers'])
                );
            }

            return $this->executeRequest($url, $payload)
                ->then(function (Response $response) use ($cacheKey) {
                    if ($response->successful()) {
                        $this->cache->set($cacheKey, $response, $this->cacheConfig->ttlSeconds);
                    }
                    return $response;
                });
        }

        return $this->executeRequest($url, $payload);
    }

    /**
     * Execute HTTP request without caching
     */
    private function executeRequest(string $url, array $payload): PromiseInterface
    {
        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(60)
            ->retry(3, 2.0, 2.0)
            ->post($url, $payload);
    }

    /**
     * Make a raw streaming SSE request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @param callable(string, SSEEvent): void $onChunk
     * @param SSEReconnectConfig $reconnectConfig
     * @return PromiseInterface<GeminiStreamResponse>
     */
    public function makeStreamRequest(
        string $url,
        array $payload,
        callable $onChunk,
        SSEReconnectConfig $reconnectConfig
    ): PromiseInterface {
        $streamResponse = null;

        return Http::withJson($payload)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(120)
            ->sseReconnect(
                maxAttempts: $reconnectConfig->maxAttempts,
                initialDelay: $reconnectConfig->initialDelay,
                maxDelay: $reconnectConfig->maxDelay,
                backoffMultiplier: $reconnectConfig->backoffMultiplier
            )
            ->sse(
                $url,
                onEvent: function (SSEEvent $event) use ($onChunk, &$streamResponse) {
                    if ($event->isKeepAlive()) {
                        return;
                    }

                    if ($event->data === null) {
                        return;
                    }

                    $chunks = $this->builder->parseSSEData($event->data);

                    foreach ($chunks as $chunk) {
                        if ($streamResponse !== null) {
                            $streamResponse->addChunk($chunk);
                            $streamResponse->addEvent($event);
                        }

                        $onChunk($chunk, $event);
                    }
                },
                onError: function (Throwable $error) use ($reconnectConfig) {
                    if ($reconnectConfig->onReconnect !== null) {
                        error_log("SSE Error: " . $error->getMessage());
                    }
                },
                reconnectConfig: $reconnectConfig
            )
            ->then(function (SSEResponse $sseResponse) use (&$streamResponse) {
                $streamResponse = new GeminiStreamResponse($sseResponse, $this->builder);
                return $streamResponse;
            });
    }
}
