<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Rcalicdan\GeminiClient\Interfaces\GeminiResponseInterface;

class GeminiResponse implements GeminiResponseInterface
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
