<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiEmbeddingInterface;

class GeminiEmbedding implements GeminiEmbeddingInterface
{
    /**
     * @var string|array<string>
     */
    private string|array $content;

    private string $model;

    private string $taskType = 'RETRIEVAL_DOCUMENT';

    private ?string $title = null;

    private ?int $outputDimensionality = null;

    /**
     * @param string|array<string> $content
     */
    public function __construct(
        private GeminiHttpRequest $httpClient,
        private GeminiRequestBuilder $builder,
        string|array $content,
        string $defaultModel
    ) {
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
