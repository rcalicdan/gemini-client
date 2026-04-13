<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\SSE\SSEEvent;

interface GeminiStreamResponseInterface
{
    /**
     * Get the raw SSE response.
     */
    public function raw(): SSEResponseInterface;

    /**
     * Add a chunk to the accumulated text.
     *
     * @internal Used by the client to build the response.
     */
    public function addChunk(string $chunk): void;

    /**
     * Add an event to the event history.
     *
     * @internal Used by the client to build the response.
     */
    public function addEvent(SSEEvent $event): void;

    /**
     * Get the complete text accumulated from all streamed chunks.
     */
    public function text(): string;

    /**
     * Get all individual chunks received during the stream.
     *
     * @return array<string>
     */
    public function chunks(): array;

    /**
     * Get the total number of text chunks received.
     */
    public function chunkCount(): int;

    /**
     * Get all raw SSE events received during the stream.
     *
     * @return array<SSEEvent>
     */
    public function events(): array;

    /**
     * Get the total number of SSE events received.
     */
    public function eventCount(): int;

    /**
     * Get the ID of the last received SSE event, if any.
     */
    public function lastEventId(): ?string;

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
     * Format a given chunk as a Server-Sent Event string.
     */
    public function formatAsSSE(string $chunk, ?string $eventType = null): string;

    /**
     * Format the complete accumulated text as a Server-Sent Event string.
     */
    public function formatCompleteAsSSE(?string $eventType = 'complete'): string;
}
