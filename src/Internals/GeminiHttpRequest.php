<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Internal HTTP client for making Gemini API requests
 */
class GeminiHttpRequest
{
    private string $apiKey;
    private array $defaultHeaders;
    private GeminiRequestBuilder $builder;

    public function __construct(string $apiKey, array $defaultHeaders, GeminiRequestBuilder $builder)
    {
        $this->apiKey = $apiKey;
        $this->defaultHeaders = $defaultHeaders;
        $this->builder = $builder;
    }

    /**
     * Make a standard HTTP request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @return PromiseInterface<Response>
     */
    public function makeRequest(string $url, array $payload): PromiseInterface
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
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function makeStreamRequest(
        string $url,
        array $payload,
        callable $onChunk,
        SSEReconnectConfig $reconnectConfig
    ): CancellablePromiseInterface {
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
                onEvent: function(SSEEvent $event) use ($onChunk, &$streamResponse) {
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
                onError: function(Throwable $error) use ($reconnectConfig) {
                    if ($reconnectConfig->onReconnect !== null) {
                        error_log("SSE Error: " . $error->getMessage());
                    }
                },
                reconnectConfig: $reconnectConfig
            )
            ->then(function(SSEResponse $sseResponse) use (&$streamResponse) {
                $streamResponse = new GeminiStreamResponse($sseResponse, $this->builder);
                return $streamResponse;
            });
    }
}