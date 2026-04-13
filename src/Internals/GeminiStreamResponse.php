<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\SSE\SSEEvent;
use Rcalicdan\GeminiClient\Interfaces\GeminiStreamResponseInterface;

class GeminiStreamResponse implements GeminiStreamResponseInterface
{
    private string $fullText = '';

    /**
     *  @var array<string>
     */
    private array $chunks = [];

    /**
     *  @var array<SSEEvent>
     */
    private array $events = [];

    public function __construct(
        private SSEResponseInterface $sseResponse,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function raw(): SSEResponseInterface
    {
        return $this->sseResponse;
    }

    /**
     * {@inheritDoc}
     */
    public function addChunk(string $chunk): void
    {
        $this->fullText .= $chunk;
        $this->chunks[] = $chunk;
    }

    /**
     * {@inheritDoc}
     */
    public function addEvent(SSEEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * {@inheritDoc}
     */
    public function text(): string
    {
        return $this->fullText;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string>
     */
    public function chunks(): array
    {
        return $this->chunks;
    }

    /**
     * {@inheritDoc}
     */
    public function chunkCount(): int
    {
        return \count($this->chunks);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<SSEEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * {@inheritDoc}
     */
    public function eventCount(): int
    {
        return \count($this->events);
    }

    /**
     * {@inheritDoc}
     */
    public function lastEventId(): ?string
    {
        return $this->sseResponse->getLastEventId();
    }

    /**
     * {@inheritDoc}
     */
    public function status(): int
    {
        return $this->sseResponse->status();
    }

    /**
     * {@inheritDoc}
     */
    public function headers(): array
    {
        return $this->sseResponse->headers();
    }

    /**
     * {@inheritDoc}
     */
    public function successful(): bool
    {
        return $this->sseResponse->successful();
    }

    /**
     * {@inheritDoc}
     */
    public function formatAsSSE(string $chunk, ?string $eventType = null): string
    {
        $sse = '';

        if ($eventType !== null) {
            $sse .= "event: {$eventType}\n";
        }

        $sse .= 'data: ' . json_encode(['content' => $chunk]) . "\n\n";

        return $sse;
    }

    /**
     * {@inheritDoc}
     */
    public function formatCompleteAsSSE(?string $eventType = 'complete'): string
    {
        $sse = '';

        if ($eventType !== null) {
            $sse .= "event: {$eventType}\n";
        }

        $sse .= 'data: ' . json_encode([
            'content' => $this->fullText,
            'chunks' => count($this->chunks),
            'status' => 'complete',
        ]) . "\n\n";

        return $sse;
    }
}
