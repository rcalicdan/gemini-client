<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\HttpClient\Response;

interface GeminiEmbeddingResponseInterface
{
    /**
     * Get the raw HTTP response.
     */
    public function raw(): Response;

    /**
     * Get the response as a JSON array.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException If the response format is invalid or an API error occurred.
     */
    public function json(): array;

    /**
     * Get the raw embedding values directly.
     *
     * @return array<float>|array<array<float>>
     * @throws \RuntimeException If no embeddings were found in the response.
     */
    public function values(): array;

    /**
     * Alias for values() - get the embedding vector(s).
     *
     * @return array<float>|array<array<float>>
     */
    public function embeddings(): array;
}
