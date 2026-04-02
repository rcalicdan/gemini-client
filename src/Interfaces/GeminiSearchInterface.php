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
     * Execute the semantic search.
     *
     * @return PromiseInterface<array<array{text: string, similarity: float, index: int}>>
     */
    public function send(): PromiseInterface;
}
