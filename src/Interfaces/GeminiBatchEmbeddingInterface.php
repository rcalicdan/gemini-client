<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

interface GeminiBatchEmbeddingInterface
{
    /**
     * Override the default embedding model.
     */
    public function model(string $model): static;

    /**
     * Add a document to the batch.
     */
    public function add(string $content, string $taskType = 'RETRIEVAL_DOCUMENT', ?string $title = null): static;

    /**
     * Execute the batch embedding request.
     *
     * @return PromiseInterface<GeminiEmbeddingResponseInterface>
     */
    public function send(): PromiseInterface;
}
