<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Rcalicdan\GeminiClient\Interfaces\GeminiEmbeddingResponseInterface;

class GeminiEmbeddingResponse implements GeminiEmbeddingResponseInterface
{
    private Response $response;
    private GeminiRequestBuilder $builder;

    public function __construct(Response $response, GeminiRequestBuilder $builder)
    {
        $this->response = $response;
        $this->builder = $builder;
    }

    /**
     * {@inheritDoc}
     */
    public function raw(): Response
    {
        return $this->response;
    }

    /**
     * {@inheritDoc}
     */
    public function json(): array
    {
        $data = $this->response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';

            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function values(): array
    {
        return $this->builder->extractEmbeddingsFromResponse($this->response);
    }

    /**
     * {@inheritDoc}
     */
    public function embeddings(): array
    {
        return $this->values();
    }
}
