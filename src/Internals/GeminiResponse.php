<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Wrapper for Gemini API responses with convenient access methods
 */
class GeminiResponse
{
    private Response $response;
    private GeminiRequestBuilder $builder;

    public function __construct(Response $response, GeminiRequestBuilder $builder)
    {
        $this->response = $response;
        $this->builder = $builder;
    }

    /**
     * Get the raw HTTP response.
     */
    public function raw(): Response
    {
        return $this->response;
    }

    /**
     * Get the response as JSON array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $data = $this->response->json();
        
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';
            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        return $data;
    }

    /**
     * Extract text content from the response.
     */
    public function text(): string
    {
        return $this->builder->extractTextFromResponse($this->response);
    }

    /**
     * Get response status code.
     */
    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * Get response headers.
     *
     * @return array<string, string|array<string>>
     */
    public function headers(): array
    {
        return $this->response->headers();
    }

    /**
     * Check if response was successful.
     */
    public function successful(): bool
    {
        return $this->response->successful();
    }

    /**
     * Get the first candidate content.
     *
     * @return array<string, mixed>|null
     */
    public function candidate(): ?array
    {
        $data = $this->json();
        $candidates = $data['candidates'] ?? [];
        
        return $candidates[0] ?? null;
    }

    /**
     * Get all candidates.
     *
     * @return array<array<string, mixed>>
     */
    public function candidates(): array
    {
        $data = $this->json();
        return $data['candidates'] ?? [];
    }

    /**
     * Get usage metadata.
     *
     * @return array<string, mixed>|null
     */
    public function usage(): ?array
    {
        $data = $this->json();
        return $data['usageMetadata'] ?? null;
    }

    /**
     * Get model version used.
     */
    public function modelVersion(): ?string
    {
        $data = $this->json();
        return $data['modelVersion'] ?? null;
    }
}