<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Flexible Gemini API Client with support for generation, embeddings, and more.
 */
class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';
    private const API_VERSION = 'v1beta';

    private string $apiKey;
    private ?string $model = null;
    private ?SSEReconnectConfig $defaultReconnectConfig = null;
    private array $defaultHeaders = [];

    /**
     * @param string $apiKey Your Gemini API key
     * @param string|null $model Default model name (optional)
     */
    public function __construct(string $apiKey, ?string $model = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;

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
    // GENERATION API
    // ==========================================

    /**
     * Generate content with JSON response.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @return PromiseInterface<Response>
     */
    public function generate(
        string|array $prompt,
        array $options = [],
        ?string $model = null
    ): PromiseInterface {
        $model = $model ?? $this->model ?? 'gemini-1.5-pro';
        $payload = $this->buildGenerationPayload($prompt, $options);
        $url = $this->buildModelUrl($model, 'generateContent');

        return $this->makeRequest($url, $payload);
    }

    /**
     * Alias for generate() - more intuitive naming.
     */
    public function json(string|array $prompt, array $options = [], ?string $model = null): PromiseInterface
    {
        return $this->generate($prompt, $options, $model);
    }

    /**
     * Generate content and extract text response.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @return PromiseInterface<string>
     */
    public function text(
        string|array $prompt,
        array $options = [],
        ?string $model = null
    ): PromiseInterface {
        return $this->generate($prompt, $options, $model)
            ->then(function (Response $response) {
                return $this->extractTextFromResponse($response);
            });
    }

    /**
     * Generate content with streaming using SSE.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param callable(string): void $onChunk Callback for each text chunk
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @param SSEReconnectConfig|null $reconnectConfig Custom reconnection config
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function stream(
        string|array $prompt,
        callable $onChunk,
        array $options = [],
        ?string $model = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $model = $model ?? $this->model ?? 'gemini-2.0-flash';
        $payload = $this->buildGenerationPayload($prompt, $options);
        $url = $this->buildModelUrl($model, 'streamGenerateContent', ['alt' => 'sse']);

        $reconnectConfig = $reconnectConfig ?? $this->defaultReconnectConfig;

        return $this->makeStreamRequest(
            $url,
            $payload,
            function (SSEEvent $event) use ($onChunk) {
                $this->handleGenerationSSEEvent($event, $onChunk);
            },
            $reconnectConfig
        );
    }

    /**
     * Generate content with streaming and collect full response.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     * @param array<string, mixed> $options Additional generation options
     * @param string|null $model Override default model
     * @param SSEReconnectConfig|null $reconnectConfig Custom reconnection config
     * @return CancellablePromiseInterface<string>
     */
    public function streamText(
        string|array $prompt,
        array $options = [],
        ?string $model = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $fullText = '';

        return $this->stream(
            $prompt,
            function (string $chunk) use (&$fullText) {
                $fullText .= $chunk;
            },
            $options,
            $model,
            $reconnectConfig
        )->then(function () use (&$fullText) {
            return $fullText;
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
     * @return PromiseInterface<Response>
     */
    public function embed(
        string|array $content,
        string $taskType = 'RETRIEVAL_DOCUMENT',
        ?string $model = null,
        ?string $title = null
    ): PromiseInterface {
        $model = $model ?? 'text-embedding-004';
        $payload = $this->buildEmbeddingPayload($content, $taskType, $title);
        $url = $this->buildModelUrl($model, 'embedContent');

        return $this->makeRequest($url, $payload);
    }

    /**
     * Generate embeddings and extract values directly.
     *
     * @param string|array<string> $content Single text or array of texts
     * @param string $taskType Task type
     * @param string|null $model Override default model
     * @param string|null $title Optional title
     * @return PromiseInterface<array<float>|array<array<float>>>
     */
    public function embedValues(
        string|array $content,
        string $taskType = 'RETRIEVAL_DOCUMENT',
        ?string $model = null,
        ?string $title = null
    ): PromiseInterface {
        return $this->embed($content, $taskType, $model, $title)
            ->then(function (Response $response) {
                return $this->extractEmbeddingsFromResponse($response);
            });
    }

    /**
     * Batch embed multiple contents.
     *
     * @param array<array{content: string, task_type?: string, title?: string}> $requests
     * @param string|null $model Override default model
     * @return PromiseInterface<Response>
     */
    public function batchEmbed(array $requests, ?string $model = null): PromiseInterface
    {
        $model = $model ?? 'text-embedding-004';
        $payload = $this->buildBatchEmbeddingPayload($requests);
        $url = $this->buildModelUrl($model, 'batchEmbedContents');

        return $this->makeRequest($url, $payload);
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
        // Embed query
        $queryPromise = $this->embedValues($query, 'RETRIEVAL_QUERY', $model);

        // Embed documents
        $docPromises = [];
        foreach ($documents as $doc) {
            $docPromises[] = $this->embedValues($doc, 'RETRIEVAL_DOCUMENT', $model);
        }

        return $queryPromise->then(function ($queryEmbedding) use ($docPromises, $documents) {
            return \Hibla\Promise\Promise::all($docPromises)
                ->then(function ($docEmbeddings) use ($queryEmbedding, $documents) {
                    $results = [];

                    foreach ($docEmbeddings as $index => $docEmbedding) {
                        $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);
                        $results[] = [
                            'text' => $documents[$index],
                            'similarity' => $similarity,
                            'index' => $index,
                        ];
                    }

                    // Sort by similarity (descending)
                    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

                    return $results;
                });
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
        $url = sprintf('%s/%s/models', self::BASE_URL, self::API_VERSION);

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
        $url = sprintf('%s/%s/models/%s', self::BASE_URL, self::API_VERSION, $model);

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
     * Build generation payload.
     *
     * @param string|array<mixed> $prompt
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildGenerationPayload(string|array $prompt, array $options): array
    {
        $contents = is_string($prompt)
            ? [['parts' => [['text' => $prompt]]]]
            : (isset($prompt['parts']) ? [$prompt] : $prompt);

        $payload = ['contents' => $contents];

        foreach (['generationConfig', 'safetySettings', 'systemInstruction', 'tools'] as $key) {
            if (isset($options[$key])) {
                $payload[$key] = $options[$key];
            }
        }

        return $payload;
    }

    /**
     * Build embedding payload.
     *
     * @param string|array<string> $content
     * @param string $taskType
     * @param string|null $title
     * @return array<string, mixed>
     */
    private function buildEmbeddingPayload(
        string|array $content,
        string $taskType,
        ?string $title = null
    ): array {
        $payload = [
            'task_type' => $taskType,
        ];

        if (is_string($content)) {
            $payload['content'] = [
                'parts' => [['text' => $content]]
            ];
        } else {
            $payload['content'] = [
                'parts' => array_map(fn($text) => ['text' => $text], $content)
            ];
        }

        if ($title !== null) {
            $payload['title'] = $title;
        }

        return $payload;
    }

    /**
     * Build batch embedding payload.
     *
     * @param array<array{content: string, task_type?: string, title?: string}> $requests
     * @return array<string, mixed>
     */
    private function buildBatchEmbeddingPayload(array $requests): array
    {
        $formattedRequests = [];

        foreach ($requests as $request) {
            $formattedRequest = [
                'model' => 'models/' . ($this->model ?? 'text-embedding-004'),
                'content' => [
                    'parts' => [['text' => $request['content']]]
                ],
                'task_type' => $request['task_type'] ?? 'RETRIEVAL_DOCUMENT',
            ];

            if (isset($request['title'])) {
                $formattedRequest['title'] = $request['title'];
            }

            $formattedRequests[] = $formattedRequest;
        }

        return ['requests' => $formattedRequests];
    }

    /**
     * Build model URL.
     *
     * @param string $model
     * @param string $endpoint
     * @param array<string, string> $queryParams
     * @return string
     */
    private function buildModelUrl(string $model, string $endpoint, array $queryParams = []): string
    {
        $url = sprintf(
            '%s/%s/models/%s:%s',
            self::BASE_URL,
            self::API_VERSION,
            $model,
            $endpoint
        );

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

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

    /**
     * Extract text from generation response.
     */
    private function extractTextFromResponse(Response $response): string
    {
        $data = $response->json();

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';
            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        $candidates = $data['candidates'] ?? [];

        if (empty($candidates) && isset($data[0]['candidates'])) {
            $candidates = $data[0]['candidates'];
        }

        if (empty($candidates)) {
            if (isset($data['content']['parts'])) {
                $parts = $data['content']['parts'];
                $text = '';
                foreach ($parts as $part) {
                    if (isset($part['text'])) {
                        $text .= $part['text'];
                    }
                }
                return $text;
            }

            throw new \RuntimeException('No candidates in response. Response structure: ' . json_encode(array_keys($data)));
        }

        $content = $candidates[0]['content'] ?? [];
        $parts = $content['parts'] ?? [];

        if (empty($parts)) {
            throw new \RuntimeException('No parts in candidate content');
        }

        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (empty($text)) {
            throw new \RuntimeException('No text content found in response parts');
        }

        return $text;
    }

    /**
     * Extract embeddings from response.
     *
     * @return array<float>|array<array<float>>
     */
    private function extractEmbeddingsFromResponse(Response $response): array
    {
        $data = $response->json();

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['embedding']['values'])) {
            return $data['embedding']['values'];
        }

        if (isset($data['embeddings'])) {
            return array_map(fn($emb) => $emb['values'] ?? [], $data['embeddings']);
        }

        throw new \RuntimeException('No embeddings found in response');
    }

    /**
     * Handle SSE event for generation.
     */
    private function handleGenerationSSEEvent(SSEEvent $event, callable $onChunk): void
    {
        if ($event->isKeepAlive()) {
            return;
        }

        $data = $event->data;
        if ($data === null) {
            return;
        }

        $parsed = json_decode($data, true);
        if (!is_array($parsed)) {
            return;
        }

        $candidates = $parsed['candidates'] ?? [];
        foreach ($candidates as $candidate) {
            $content = $candidate['content'] ?? [];
            $parts = $content['parts'] ?? [];

            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $onChunk($part['text']);
                }
            }
        }
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array<float> $a
     * @param array<float> $b
     * @return float
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException('Vectors must have the same length');
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
}