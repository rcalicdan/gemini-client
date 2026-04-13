<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Internal HTTP client for making Gemini API requests
 */
class GeminiHttpRequest
{
    /**
     * @param array<string, array<string>|string> $defaultHeaders
     */
    public function __construct(
        private string $apiKey,
        private array $defaultHeaders,
        private GeminiRequestBuilder $builder,
        private HttpClientInterface $client,
        private RetryConfig $retryConfig
    ) {
    }

    /**
     * Make a standard HTTP request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     *
     * @return PromiseInterface<Response>
     */
    public function makeRequest(string $url, array $payload): PromiseInterface
    {
        return $this->client
            ->asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(60)
            ->withRetryConfig($this->retryConfig)
            ->post($url, $payload)
            ->then(function (mixed $response) use ($url): Response {
                if (! $response instanceof Response) {
                    throw new \RuntimeException('Expected Response instance from ' . $url);
                }

                return $response;
            })
        ;
    }

    /**
     * Make a raw streaming SSE request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @param callable(string, SSEEvent): void $onChunk
     * @param SSEReconnectConfig $reconnectConfig
     *
     * @return PromiseInterface<GeminiStreamResponse>
     */
    public function makeStreamRequest(
        string $url,
        array $payload,
        callable $onChunk,
        SSEReconnectConfig $reconnectConfig
    ): PromiseInterface {
        /** @var array<string> $bufferedChunks */
        $bufferedChunks = [];

        /** @var array<SSEEvent> $bufferedEvents */
        $bufferedEvents = [];

        /** @var GeminiStreamResponse|null $streamResponse */
        $streamResponse = null;

        return $this->client
            ->withMethod('POST')
            ->withJson($payload)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(120)
            ->sse($url)
            ->withReconnectConfig($reconnectConfig)
            ->onEvent(function (mixed $event, mixed $control) use ($onChunk, &$bufferedChunks, &$bufferedEvents, &$streamResponse) {
                if (! $event instanceof SSEEvent) {
                    return;
                }

                if ($event->isKeepAlive()) {
                    if ($streamResponse !== null) {
                        $streamResponse->addEvent($event);
                    } else {
                        $bufferedEvents[] = $event;
                    }

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
                    } else {
                        $bufferedChunks[] = $chunk;
                        $bufferedEvents[] = $event;
                    }

                    $onChunk($chunk, $event);
                }
            })
            ->onError(function (Throwable $error) use ($reconnectConfig) {
                if ($reconnectConfig->onReconnect !== null) {
                    error_log('SSE Error: ' . $error->getMessage());
                }
            })
            ->connect()
            ->then(function (SSEResponse $sseResponse) use (&$bufferedChunks, &$bufferedEvents, &$streamResponse) {
                $streamResponse = new GeminiStreamResponse($sseResponse);

                foreach ($bufferedChunks as $chunk) {
                    $streamResponse->addChunk($chunk);
                }

                foreach ($bufferedEvents as $event) {
                    $streamResponse->addEvent($event);
                }

                // Clear buffers to free memory
                $bufferedChunks = [];
                $bufferedEvents = [];

                return $streamResponse;
            })
        ;
    }
}
