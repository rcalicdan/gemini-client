<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\HttpClient\Interfaces\ResponseInterface;

interface GeminiResponseInterface
{
    /**
     * Get the raw HTTP response.
     */
    public function raw(): ResponseInterface;

    /**
     * Get the response as a JSON array.
     *
     * @return mixed
     *
     * @throws \RuntimeException If the format is invalid or an API error occurred.
     */
    public function json(?string $key = null, mixed $default = null): mixed;

    /**
     * Extract the generated text content from the response.
     *
     * @throws \RuntimeException If no text could be extracted.
     */
    public function text(): string;

    /**
     * Get the HTTP response status code.
     */
    public function status(): int;

    /**
     * Get the response headers.
     *
     * @return array<string, string|array<string>>
     */
    public function headers(): array;

    /**
     * Check if the HTTP response was successful (2xx status code).
     */
    public function successful(): bool;

    /**
     * Get the first candidate's content.
     *
     * @return array<string, mixed>|null
     */
    public function candidate(): ?array;

    /**
     * Get all returned candidates.
     *
     * @return array<array<string, mixed>>
     */
    public function candidates(): array;

    /**
     * Get token usage metadata.
     *
     * @return array<string, mixed>|null
     */
    public function usage(): ?array;

    /**
     * Get the specific model version used to generate the response.
     */
    public function modelVersion(): ?string;
}
