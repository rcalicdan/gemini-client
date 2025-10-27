<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\Response;

/**
 * Wrapper for Gemini embedding responses
 */
class GeminiEmbeddingResponse
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
     * Get embedding values.
     *
     * @return array<float>|array<array<float>>
     */
    public function values(): array
    {
        return $this->builder->extractEmbeddingsFromResponse($this->response);
    }

    /**
     * Alias for values() - get the embedding vector(s).
     *
     * @return array<float>|array<array<float>>
     */
    public function embeddings(): array
    {
        return $this->values();
    }
}