<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

interface GeminiSearchInterface
{
    /**
     * Set the documents to search against.
     *
     * @param array<string> $documents
     */
    public function documents(array $documents): static;

    /**
     * Override the default embedding model used for the search.
     */
    public function model(string $model): static;

    /**
     * Set the output dimensionality for all embeddings in the search.
     *
     * Query and document embeddings MUST use the same dimensionality —
     * comparing vectors of different sizes will throw an exception.
     *
     * Recommended values: 3072 (default/full), 1536, 768.
     *
     * @param int $dimensions Target number of output dimensions
     */
    public function outputDimensionality(int $dimensions): static;

    /**
     * Execute the semantic search.
     *
     * @return PromiseInterface<array<array{text: string, similarity: float, index: int}>>
     */
    public function send(): PromiseInterface;
}