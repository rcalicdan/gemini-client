<?php

namespace Rcalicdan\GeminiClient;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEResponse;

/**
 * Wrapper for Gemini streaming responses
 */
class GeminiStreamResponse
{
    private SSEResponse $response;
    private GeminiRequestBuilder $builder;
    private string $fullText = '';
    private array $chunks = [];

    public function __construct(SSEResponse $response, GeminiRequestBuilder $builder)
    {
        $this->response = $response;
        $this->builder = $builder;
    }

    /**
     * Get the raw SSE response.
     */
    public function raw(): SSEResponse
    {
        return $this->response;
    }

    /**
     * Add a chunk to the accumulated text.
     */
    public function addChunk(string $chunk): void
    {
        $this->fullText .= $chunk;
        $this->chunks[] = $chunk;
    }

    /**
     * Get the complete text accumulated from all chunks.
     */
    public function text(): string
    {
        return $this->fullText;
    }

    /**
     * Get all individual chunks.
     *
     * @return array<string>
     */
    public function chunks(): array
    {
        return $this->chunks;
    }

    /**
     * Get the number of chunks received.
     */
    public function chunkCount(): int
    {
        return count($this->chunks);
    }
}