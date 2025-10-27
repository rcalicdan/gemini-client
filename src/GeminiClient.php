<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\GeminiClient\Internals\GeminiEmbeddingResponse;
use Rcalicdan\GeminiClient\Internals\GeminiHttpRequest;
use Rcalicdan\GeminiClient\Internals\GeminiPrompt;
use Rcalicdan\GeminiClient\Internals\GeminiRequestBuilder;
use Rcalicdan\GeminiClient\Internals\GeminiResponse;
use Rcalicdan\GeminiClient\Internals\GeminiStreamResponse;

use function Hibla\async;
use function Hibla\await;

/**
 * Flexible Gemini API Client with support for generation, embeddings, and more.
 */
class GeminiClient
{
    private string $apiKey;
    private ?string $model = null;
    private ?string $embeddingModel = null;
    private ?SSEReconnectConfig $defaultReconnectConfig = null;
    private array $defaultHeaders = [];
    private GeminiRequestBuilder $builder;
    private GeminiHttpRequest $httpClient;

    /**
     * @param string $apiKey Your Gemini API key
     * @param string|null $model Default model name (optional)
     */
    public function __construct(string $apiKey, ?string $model = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->builder = new GeminiRequestBuilder();
        $this->httpClient = new GeminiHttpRequest($apiKey, $this->defaultHeaders, $this->builder);

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
    public function generateContent(
        string|array $prompt,
        array $options = [],
        ?string $model = null
    ): PromiseInterface {
        $model ??= $this->model ?? 'gemini-2.0-flash';
        $payload = $this->builder->buildGenerationPayload($prompt, $options);
        $url = $this->builder->buildModelUrl($model, endpoint: 'generateContent');

        return $this->httpClient->makeRequest($url, $payload)
            ->then(fn(Response $response) => new GeminiResponse($response, $this->builder));
    }

    /**
     * Generate content with streaming using SSE.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param callable(string, SSEEvent): void $onChunk Callback for each text chunk and event
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @param SSEReconnectConfig|null $reconnectConfig Custom reconnection config
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamGenerateContent(
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

        return $this->httpClient->makeStreamRequest(
            $url,
            $payload,
            $onChunk,
            $reconnectConfig
        );
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
    public function embedContent(
        string|array $content,
        string $taskType = 'RETRIEVAL_DOCUMENT',
        ?string $model = null,
        ?string $title = null
    ): PromiseInterface {
        $model ??= $this->embeddingModel ?? 'text-embedding-004';
        $payload = $this->builder->buildEmbeddingPayload($content, $taskType, $title);
        $url = $this->builder->buildModelUrl($model, 'embedContent');

        return $this->httpClient->makeRequest($url, $payload)
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
        $model ??= $this->embeddingModel ?? 'text-embedding-004';
        $payload = $this->builder->buildBatchEmbeddingPayload($requests, $model);
        $url = $this->builder->buildModelUrl($model, 'batchEmbedContents');

        return $this->httpClient->makeRequest($url, $payload)
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
        return async(function() use ($query, $documents, $model) {
            $queryResponse = await($this->embedContent($query, 'RETRIEVAL_QUERY', $model));
            $queryEmbedding = $queryResponse->values();

            $docPromises = [];
            foreach ($documents as $doc) {
                $docPromises[] = $this->embedContent($doc, 'RETRIEVAL_DOCUMENT', $model);
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
     * Set default model for generation.
     */
    public function withModel(string $model): self
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    /**
     * Set default model for embeddings.
     */
    public function withEmbeddingModel(string $model): self
    {
        $clone = clone $this;
        $clone->embeddingModel = $model;
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
        $clone->httpClient = new GeminiHttpRequest($clone->apiKey, $clone->defaultHeaders, $clone->builder);
        return $clone;
    }

    /**
     * Get the current generation model name.
     */
    public function getModelName(): ?string
    {
        return $this->model;
    }

    /**
     * Get the current embedding model name.
     */
    public function getEmbeddingModelName(): ?string
    {
        return $this->embeddingModel;
    }
}