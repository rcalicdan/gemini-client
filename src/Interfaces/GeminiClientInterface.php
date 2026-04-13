<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

interface GeminiClientInterface
{
    /**
     * Start building a content generation prompt.
     *
     * @param string|array<mixed> $prompt Text prompt or structured content
     */
    public function prompt(string|array $prompt): GeminiPromptInterface;

    /**
     * Start building an embedding request.
     *
     * @param string|array<string> $content Single text or array of texts
     */
    public function embed(string|array $content): GeminiEmbeddingInterface;

    /**
     * Start building a batch embedding request.
     */
    public function batchEmbed(): GeminiBatchEmbeddingInterface;

    /**
     * Start building a semantic search query.
     */
    public function search(string $query): GeminiSearchInterface;

    /**
     * List available models.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function listModels(): PromiseInterface;

    /**
     * Get information about a specific model.
     *
     * @param string $model Model name
     * @return PromiseInterface<ResponseInterface>
     */
    public function getModel(string $model): PromiseInterface;

    /**
     * Set default model for generation.
     */
    public function withModel(string $model): static;

    /**
     * Configure the retry behavior for the API.
     */
    public function withRetryConfig(RetryConfig $config): static;

    /**
     * Set default model for embeddings.
     */
    public function withEmbeddingModel(string $model): static;

    /**
     * Set custom default reconnection configuration for SSE streams.
     */
    public function withReconnectConfig(SSEReconnectConfig $config): static;

    /**
     * Add default headers to all HTTP requests.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static;
}
