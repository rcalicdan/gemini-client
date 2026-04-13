<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\SSE\SSEControlInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
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
    private HttpClientInterface $client;

    public function __construct(
        string $apiKey,
        array $defaultHeaders,
        GeminiRequestBuilder $builder,
        HttpClientInterface $client
    ) {
        $this->apiKey = $apiKey;
        $this->defaultHeaders = $defaultHeaders;
        $this->builder = $builder;
        $this->client = $client;
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
        return $this->client
            ->asJson()
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

        return $this->client
            ->withJson($payload)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(120)
            ->sse($url)
            ->withReconnectConfig($reconnectConfig)
            ->onEvent(function (SSEEvent $event, SSEControlInterface $control) use ($onChunk, &$streamResponse) {
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
            })
            ->onError(function (Throwable $error) use ($reconnectConfig) {
                if ($reconnectConfig->onReconnect !== null) {
                    print('SSE Error: ' . $error->getMessage());
                }
            })
            ->connect()
            ->then(function (SSEResponse $sseResponse) use (&$streamResponse) {
                $streamResponse = new GeminiStreamResponse($sseResponse, $this->builder);

                return $streamResponse;
            });
    }
}