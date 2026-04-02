<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiBatchEmbeddingInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiClientInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiEmbeddingInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiPromptInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiSearchInterface;
use Rcalicdan\GeminiClient\Internals\GeminiBatchEmbedding;
use Rcalicdan\GeminiClient\Internals\GeminiEmbedding;
use Rcalicdan\GeminiClient\Internals\GeminiHttpRequest;
use Rcalicdan\GeminiClient\Internals\GeminiPrompt;
use Rcalicdan\GeminiClient\Internals\GeminiRequestBuilder;
use Rcalicdan\GeminiClient\Internals\GeminiSearch;

use function Rcalicdan\ConfigLoader\env;

class GeminiClient implements GeminiClientInterface
{
    private string $apiKey;
    private ?string $model = null;
    private ?string $embeddingModel = null;
    private ?SSEReconnectConfig $defaultReconnectConfig = null;
    private array $defaultHeaders = [];
    private GeminiHttpRequest $httpClient;
    public private(set) GeminiRequestBuilder $builder;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = env('GEMINI_API_KEY', $apiKey);
        $this->model = $model;
        $this->builder = new GeminiRequestBuilder();
        $this->httpClient = new GeminiHttpRequest(
            $this->apiKey,
            $this->defaultHeaders,
            $this->builder
        );

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

    /**
     * {@inheritDoc}
     */
    public function prompt(string|array $prompt): GeminiPromptInterface
    {
        return new GeminiPrompt(
            $this->httpClient,
            $this->builder,
            $prompt,
            $this->model ?? 'gemini-2.0-flash',
            $this->defaultReconnectConfig
        );
    }

    /**
     * {@inheritDoc}
     */
    public function embed(string|array $content): GeminiEmbeddingInterface
    {
        return new GeminiEmbedding(
            $this->httpClient,
            $this->builder,
            $content,
            $this->embeddingModel ?? 'gemini-embedding-001'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function batchEmbed(): GeminiBatchEmbeddingInterface
    {
        return new GeminiBatchEmbedding(
            $this->httpClient,
            $this->builder,
            $this->embeddingModel ?? 'gemini-embedding-001'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $query): GeminiSearchInterface
    {
        return new GeminiSearch($this, $this->builder, $query);
    }

    /**
     * {@inheritDoc}
     */
    public function listModels(): PromiseInterface
    {
        $url = $this->builder->buildModelsUrl();

        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(30)
            ->retry(3, 1.0, 2.0)
            ->get($url)
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function getModel(string $model): PromiseInterface
    {
        $url = $this->builder->buildModelInfoUrl($model);

        return Http::asJson()
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->withHeaders($this->defaultHeaders)
            ->timeout(30)
            ->retry(3, 1.0, 2.0)
            ->get($url)
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withEmbeddingModel(string $model): static
    {
        $clone = clone $this;
        $clone->embeddingModel = $model;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withReconnectConfig(SSEReconnectConfig $config): static
    {
        $clone = clone $this;
        $clone->defaultReconnectConfig = $config;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        $clone->defaultHeaders = array_merge($clone->defaultHeaders, $headers);
        $clone->httpClient = new GeminiHttpRequest(
            $clone->apiKey,
            $clone->defaultHeaders,
            $clone->builder
        );

        return $clone;
    }
}
