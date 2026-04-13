<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiEmbeddingInterface;

class GeminiEmbedding implements GeminiEmbeddingInterface
{
    private GeminiHttpRequest $httpClient;
    private GeminiRequestBuilder $builder;
    private string|array $content;
    private string $model;
    private string $taskType = 'RETRIEVAL_DOCUMENT';
    private ?string $title = null;
    private ?int $outputDimensionality = null;

    public function __construct(
        GeminiHttpRequest $httpClient,
        GeminiRequestBuilder $builder,
        string|array $content,
        string $defaultModel
    ) {
        $this->httpClient = $httpClient;
        $this->builder = $builder;
        $this->content = $content;
        $this->model = $defaultModel;
    }

    /**
     * {@inheritDoc}
     */
    public function model(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function taskType(string $taskType): static
    {
        $clone = clone $this;
        $clone->taskType = $taskType;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function title(string $title): static
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function outputDimensionality(int $dimensions): static
    {
        $clone = clone $this;
        $clone->outputDimensionality = $dimensions;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function send(): PromiseInterface
    {
        $payload = $this->builder->buildEmbeddingPayload(
            $this->content,
            $this->taskType,
            $this->title,
            $this->outputDimensionality
        );
        $url = $this->builder->buildModelUrl($this->model, 'embedContent');

        return $this->httpClient->makeRequest($url, $payload)
            ->then(fn (Response $response) => new GeminiEmbeddingResponse($response, $this->builder))
        ;
    }
}
