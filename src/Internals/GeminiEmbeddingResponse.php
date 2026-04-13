<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiEmbeddingResponseInterface;

class GeminiEmbeddingResponse implements GeminiEmbeddingResponseInterface
{
    public function __construct(
        private ResponseInterface $response,
        private GeminiRequestBuilder $builder
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function raw(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * {@inheritDoc}
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->response->json($key, $default);

        if (! \is_array($data)) {
            return $data;
        }

        $error = $data['error'] ?? null;
        if (\is_array($error)) {
            $errorMessage = isset($error['message']) && \is_string($error['message'])
                ? $error['message']
                : 'Unknown API error';

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
