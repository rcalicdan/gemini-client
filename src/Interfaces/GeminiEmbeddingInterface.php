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
     * Set the output dimensionality of the embedding vector.
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
     * Execute the embedding request.
     *
     * @return PromiseInterface<GeminiEmbeddingResponseInterface>
     */
    public function send(): PromiseInterface;
}