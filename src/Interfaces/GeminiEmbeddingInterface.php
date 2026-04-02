<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

interface GeminiEmbeddingInterface
{
    /**
     * Override the default embedding model.
     */
    public function model(string $model): static;

    /**
     * Set the task type (e.g., RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT, SEMANTIC_SIMILARITY).
     */
    public function taskType(string $taskType): static;

    /**
     * Set the title of the document (only valid for RETRIEVAL_DOCUMENT).
     */
    public function title(string $title): static;

    /**
     * Execute the embedding request.
     *
     * @return PromiseInterface<GeminiEmbeddingResponseInterface>
     */
    public function send(): PromiseInterface;
}
