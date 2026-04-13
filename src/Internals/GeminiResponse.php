<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiResponseInterface;

class GeminiResponse implements GeminiResponseInterface
{
    private ResponseInterface $response;
    private GeminiRequestBuilder $builder;

    public function __construct(ResponseInterface $response, GeminiRequestBuilder $builder)
    {
        $this->response = $response;
        $this->builder = $builder;
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

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';

            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function text(): string
    {
        return $this->builder->extractTextFromResponse($this->response);
    }

    /**
     * {@inheritDoc}
     */
    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * {@inheritDoc}
     */
    public function headers(): array
    {
        return $this->response->headers();
    }

    /**
     * {@inheritDoc}
     */
    public function successful(): bool
    {
        return $this->response->successful();
    }

    /**
     * {@inheritDoc}
     */
    public function candidate(): ?array
    {
        $data = $this->json();
        $candidates = $data['candidates'] ?? [];

        return $candidates[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function candidates(): array
    {
        $data = $this->json();

        return $data['candidates'] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function usage(): ?array
    {
        $data = $this->json();

        return $data['usageMetadata'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function modelVersion(): ?string
    {
        $data = $this->json();

        return $data['modelVersion'] ?? null;
    }
}
