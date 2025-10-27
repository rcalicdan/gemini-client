<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEResponse;

/**
 * Wrapper for Gemini streaming responses
 */
class GeminiStreamResponse
{
    private SSEResponse $sseResponse;
    private GeminiRequestBuilder $builder;
    private string $fullText = '';
    private array $chunks = [];
    private array $events = [];

    public function __construct(SSEResponse $sseResponse, GeminiRequestBuilder $builder)
    {
        $this->sseResponse = $sseResponse;
        $this->builder = $builder;
    }

    /**
     * Get the raw SSE response.
     */
    public function raw(): SSEResponse
    {
        return $this->sseResponse;
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
     * Add an event to the event history.
     */
    public function addEvent(SSEEvent $event): void
    {
        $this->events[] = $event;
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

    /**
     * Get all SSE events received.
     *
     * @return array<SSEEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * Get the number of events received.
     */
    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * Get the last event ID.
     */
    public function lastEventId(): ?string
    {
        return $this->sseResponse->getLastEventId();
    }

    /**
     * Get status code.
     */
    public function status(): int
    {
        return $this->sseResponse->status();
    }

    /**
     * Get response headers.
     *
     * @return array<string, string|array<string>>
     */
    public function headers(): array
    {
        return $this->sseResponse->headers();
    }

    /**
     * Check if response was successful.
     */
    public function successful(): bool
    {
        return $this->sseResponse->successful();
    }
}