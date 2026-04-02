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
     * Set the output dimensionality for all embeddings in the batch.
     *
     * Supported by gemini-embedding-001 and text-embedding-004.
     * Uses Matryoshka Representation Learning (MRL) to truncate the output vector.
     * Recommended values: 3072 (default/full), 1536, 768.
     *
     * Note: Only 3072-dimensional embeddings are pre-normalized by the API.
     * If you use a smaller dimension, normalize the vector before computing
     * cosine similarity or dot product.
     *
     * @param int $dimensions Target number of output dimensions (e.g., 768, 1536, 3072)
     */
    public function outputDimensionality(int $dimensions): static;

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