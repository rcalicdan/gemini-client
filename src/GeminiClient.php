<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

/**
 * Flexible Gemini API Client with support for generation, embeddings, and more.
 */
class GeminiClient
{
    private string $apiKey;
    private ?string $model = null;
    private ?SSEReconnectConfig $defaultReconnectConfig = null;
    private array $defaultHeaders = [];
    private GeminiRequestBuilder $builder;

    /**
     * @param string $apiKey Your Gemini API key
     * @param string|null $model Default model name (optional)
     */
    public function __construct(string $apiKey, ?string $model = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->builder = new GeminiRequestBuilder();

        $this->defaultReconnectConfig = new SSEReconnectConfig(
            enabled: true,
            maxAttempts: 10,
            initialDelay: 1.0,
            maxDelay: 30.0,
            backoffMultiplier: 2.0,
            jitter: true,
            retryableErrors: [
                'Connection refused',
                'Connection reset',
                'Connection timed out',
                'Could not resolve host',
                'Operation timed out',
                'Network is unreachable',
                'HTTP/2 stream',
                'SSL connection',
            ],
            onReconnect: function (int $attempt, float $delay) {
                error_log("Gemini SSE reconnecting (attempt {$attempt}) after {$delay}s delay");
            }
        );
    }

    // ==========================================
    // FLUENT PROMPT API
    // ==========================================

    /**
     * Create a new prompt builder (fluent interface).
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @return GeminiPrompt
     */
    public function prompt(string|array $prompt): GeminiPrompt
    {
        return new GeminiPrompt($this, $prompt);
    }

    // ==========================================
    // GENERATION API
    // ==========================================

    /**
     * Generate content and return a GeminiResponse.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @return PromiseInterface<GeminiResponse>
     */
    public function generate(
        string|array $prompt,
        array $options = [],
        ?string $model = null
    ): PromiseInterface {
        $model ??= $this->model ?? 'gemini-1.5-pro';
        $payload = $this->builder->buildGenerationPayload($prompt, $options);
        $url = $this->builder->buildModelUrl($model, 'generateContent');

        return $this->makeRequest($url, $payload)
            ->then(
                fn(Response $response) => new GeminiResponse($response, $this->builder)
            );
    }

    /**
     * Generate content with streaming.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param callable(string): void $onChunk Callback for each text chunk
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @param SSEReconnectConfig|null $reconnectConfig Custom reconnection config
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamGenerate(
        string|array $prompt,
        callable $onChunk,
        array $options = [],
        ?string $model = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $model ??= $this->model ?? 'gemini-2.0-flash';
        $payload = $this->builder->buildGenerationPayload($prompt, $options);
        $url = $this->builder->buildModelUrl($model, 'streamGenerateContent', ['alt' => 'sse']);

        $reconnectConfig ??= $this->defaultReconnectConfig;
        $streamResponse = null;

        return $this->makeStreamRequest(
            $url,
            $payload,
            function (SSEEvent $event) use ($onChunk, &$streamResponse) {
                if ($event->isKeepAlive() || $event->data === null) {
                    return;
                }

                $chunks = $this->builder->parseSSEData($event->data);
                foreach ($chunks as $chunk) {
                    if ($streamResponse !== null) {
                        $streamResponse->addChunk($chunk);
                    }
                    $onChunk($chunk);
                }
            },
            $reconnectConfig
        )->then(function (SSEResponse $sseResponse) use (&$streamResponse) {
            $streamResponse = new GeminiStreamResponse($sseResponse, $this->builder);
            return $streamResponse;
        });
    }

    // ==========================================
    // EMBEDDING API
    // ==========================================

    /**
     * Generate embeddings for content.
     *
     * @param string|array<string> $content Single text or array of texts
     * @param string $taskType Task type (RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT, SEMANTIC_SIMILARITY, CLASSIFICATION, CLUSTERING)
     * @param string|null $model Override default model (default: text-embedding-004)
     * @param string|null $title Optional title for RETRIEVAL_DOCUMENT task
     * @return PromiseInterface<GeminiEmbeddingResponse>
     */
    public function embed(
        string|array $content,
        string $taskType = 'RETRIEVAL_DOCUMENT',
        ?string $model = null,
        ?string $title = null
    ): PromiseInterface {
        $model ??= 'text-embedding-004';
        $payload = $this->builder->buildEmbeddingPayload($content, $taskType, $title);
        $url = $this->builder->buildModelUrl($model, 'embedContent');

        return $this->makeRequest($url, $payload)
            ->then(fn(Response $response) => new GeminiEmbeddingResponse($response, $this->builder));
    }

    /**
     * Batch embed multiple contents.
     *
     * @param array<array{content: string, task_type?: string, title?: string}> $requests
     * @param string|null $model Override default model
     * @return PromiseInterface<GeminiEmbeddingResponse>
     */
    public function batchEmbed(array $requests, ?string $model = null): PromiseInterface
    {
        $model ??= 'text-embedding-004';
        $payload = $this->builder->buildBatchEmbeddingPayload($requests, $model);
        $url = $this->builder->buildModelUrl($model, 'batchEmbedContents');

        return $this->makeRequest($url, $payload)
            ->then(fn(Response $response) => new GeminiEmbeddingResponse($response, $this->builder));
    }

    // ==========================================
    // SEARCH/SEMANTIC SIMILARITY
    // ==========================================

    /**
     * Search using semantic similarity.
     *
     * @param string $query Search query
     * @param array<string> $documents Documents to search
     * @param string|null $model Override default model
     * @return PromiseInterface<array<array{text: string, similarity: float, index: int}>>
     */
    public function search(
        string $query,
        array $documents,
        ?string $model = null
    ): PromiseInterface {
        return async(function () use ($query, $documents, $model) {
            $queryResponse = await($this->embed($query, 'RETRIEVAL_QUERY', $model));
            $queryEmbedding = $queryResponse->values();

            $docPromises = [];
            foreach ($documents as $doc) {
                $docPromises[] = $this->embed($doc, 'RETRIEVAL_DOCUMENT', $model);
            }

            $docResponses = await(Promise::all($docPromises));

            $results = [];
            foreach ($docResponses as $index => $docResponse) {
                $docEmbedding = $docResponse->values();
                $similarity = $this->builder->cosineSimilarity($queryEmbedding, $docEmbedding);

                $results[] = [
                    'text' => $documents[$index],
                    'similarity' => $similarity,
                    'index' => $index,
                ];
            }

            usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

            return $results;
        });
    }

    // ==========================================
    // MODEL LISTING
    // ==========================================

    /**
     * List available models.
     *
     * @return PromiseInterface<Response>
     */
    public function listModels(): PromiseInterface
    {
        $url = $this->builder->buildModelsUrl();

        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(30)
            ->retry(3, 1.0, 2.0)
            ->get($url);
    }

    /**
     * Get information about a specific model.
     *
     * @param string $model Model name
     * @return PromiseInterface<Response>
     */
    public function getModel(string $model): PromiseInterface
    {
        $url = $this->builder->buildModelInfoUrl($model);

        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(30)
            ->retry(3, 1.0, 2.0)
            ->get($url);
    }

    // ==========================================
    // CONFIGURATION METHODS
    // ==========================================

    /**
     * Set default model.
     */
    public function withModel(string $model): self
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    /**
     * Set custom reconnection configuration.
     */
    public function withReconnectConfig(SSEReconnectConfig $config): self
    {
        $clone = clone $this;
        $clone->defaultReconnectConfig = $config;
        return $clone;
    }

    /**
     * Add default headers to all requests.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->defaultHeaders = array_merge($clone->defaultHeaders, $headers);
        return $clone;
    }

    /**
     * Get the current model name.
     */
    public function getModelName(): ?string
    {
        return $this->model;
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

    /**
     * Make a standard HTTP request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @return PromiseInterface<Response>
     */
    private function makeRequest(string $url, array $payload): PromiseInterface
    {
        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(60)
            ->retry(3, 2.0, 2.0)
            ->post($url, $payload);
    }

    /**
     * Make a streaming SSE request.
     *
     * @param string $url
     * @param array<string, mixed> $payload
     * @param callable $onEvent
     * @param SSEReconnectConfig $reconnectConfig
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function makeStreamRequest(
        string $url,
        array $payload,
        callable $onEvent,
        SSEReconnectConfig $reconnectConfig
    ): CancellablePromiseInterface {
        return Http::withJson($payload)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(120)
            ->accept('text/event-stream')
            ->post($url)
            ->then(function ($response) use ($onEvent, $reconnectConfig) {
                return $response;
            });
    }
}
