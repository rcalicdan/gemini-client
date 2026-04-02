<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiBatchEmbeddingInterface;

class GeminiBatchEmbedding implements GeminiBatchEmbeddingInterface
{
    private GeminiHttpRequest $httpClient;
    private GeminiRequestBuilder $builder;
    private string $model;
    private array $requests = [];

    public function __construct(
        GeminiHttpRequest $httpClient,
        GeminiRequestBuilder $builder,
        string $defaultModel
    ) {
        $this->httpClient = $httpClient;
        $this->builder = $builder;
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
    public function add(string $content, string $taskType = 'RETRIEVAL_DOCUMENT', ?string $title = null): static
    {
        $clone = clone $this;

        $request = ['content' => $content, 'task_type' => $taskType];
        if ($title !== null) {
            $request['title'] = $title;
        }

        $clone->requests[] = $request;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function send(): PromiseInterface
    {
        if (empty($this->requests)) {
            throw new \RuntimeException('Cannot send a batch embedding request without any items.');
        }

        $payload = $this->builder->buildBatchEmbeddingPayload($this->requests, $this->model);
        $url = $this->builder->buildModelUrl($this->model, 'batchEmbedContents');

        return $this->httpClient->makeRequest($url, $payload)
            ->then(fn (Response $response) => new GeminiEmbeddingResponse($response, $this->builder))
        ;
    }
}
