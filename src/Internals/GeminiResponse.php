<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Response;
use Rcalicdan\GeminiClient\Interfaces\GeminiResponseInterface;

class GeminiResponse implements GeminiResponseInterface
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
    public function text(): string
    {
        if (! $this->response instanceof Response) {
            throw new \RuntimeException('Response must be an instance of ' . Response::class);
        }

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
     *
     * @return array<string, array<string>|string>
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
     *
     * @return array<string, mixed>|null
     */
    public function candidate(): ?array
    {
        $data = $this->json();

        if (! \is_array($data)) {
            return null;
        }

        $candidates = $data['candidates'] ?? null;
        if (! \is_array($candidates)) {
            return null;
        }

        $first = $candidates[0] ?? null;
        if (! \is_array($first)) {
            return null;
        }

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<array<string, mixed>>
     */
    public function candidates(): array
    {
        $data = $this->json();

        if (! \is_array($data)) {
            return [];
        }

        $candidates = $data['candidates'] ?? null;
        if (! \is_array($candidates)) {
            return [];
        }

        /** @var array<array<string, mixed>> $candidates */
        return $candidates;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>|null
     */
    public function usage(): ?array
    {
        $data = $this->json();

        if (! \is_array($data)) {
            return null;
        }

        $usage = $data['usageMetadata'] ?? null;
        if (! \is_array($usage)) {
            return null;
        }

        /** @var array<string, mixed> $usage */
        return $usage;
    }

    /**
     * {@inheritDoc}
     */
    public function modelVersion(): ?string
    {
        $data = $this->json();

        if (! \is_array($data)) {
            return null;
        }

        $version = $data['modelVersion'] ?? null;

        return \is_string($version) ? $version : null;
    }
}
