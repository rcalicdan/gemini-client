<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
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
        $context = new GeminiStreamContext();

        /** @var Promise<GeminiStreamResponse> $completionPromise */
        $completionPromise = new Promise();

        $connector = $this->client
            ->withMethod('POST')
            ->withJson($payload)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(120)
            ->sse($url)
            ->withReconnectConfig($reconnectConfig)
            ->onEvent(function (mixed $event) use ($onChunk, $context, $completionPromise) {
                if (! $event instanceof SSEEvent) {
                    return;
                }

                if ($event->isKeepAlive() || $event->data === null) {
                    if ($event->isKeepAlive()) {
                        $context->addEvent($event);
                    }

                    return;
                }

                $parsed = json_decode($event->data, true);
                $finishReason = null;

                if (\is_array($parsed)) {
                    $candidates = $parsed['candidates'] ?? null;
                    if (\is_array($candidates) && isset($candidates[0]) && \is_array($candidates[0])) {
                        $finishReason = $candidates[0]['finishReason'] ?? null;
                    }
                }

                $chunks = $this->builder->parseSSEData($event->data);

                foreach ($chunks as $chunk) {
                    $context->addChunk($chunk);
                    $context->addEvent($event);
                    $onChunk($chunk, $event);
                }

                // STOP, MAX_TOKENS, SAFETY, RECITATION all mean Gemini is done
                if ($finishReason !== null && ! $completionPromise->isSettled()) {
                    $completionPromise->resolve($context->getResponse());
                }
            })
            ->onError(function (Throwable $error) use ($completionPromise): void {
                if (! $completionPromise->isSettled()) {
                    $completionPromise->reject($error);
                }
            })
        ;

        $connector
            ->connect()
            ->then(
                function (SSEResponseInterface $sseResponse) use ($context) {
                    // This fires when HTTP headers arrive. It pass it to the context
                    // which immediately flushes any early events into the response object.
                    $context->setResponse(new GeminiStreamResponse($sseResponse));
                },
                function (Throwable $error) use ($completionPromise): void {
                    if (! $completionPromise->isSettled()) {
                        $completionPromise->reject($error);
                    }
                }
            )
        ;

        return $completionPromise;
    }
}
