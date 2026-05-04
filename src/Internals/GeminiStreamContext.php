<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;

/**
 * @internal Holds the state of an active SSE stream before the HTTP response is fully constructed.
 */
class GeminiStreamContext
{
    /**
     * @var array<string>
     */
    private array $bufferedChunks = [];

    /**
     * @var array<SSEEvent>
     */
    private array $bufferedEvents = [];

    private ?GeminiStreamResponse $response = null;

    public function addChunk(string $chunk): void
    {
        if ($this->response !== null) {
            $this->response->addChunk($chunk);
        } else {
            $this->bufferedChunks[] = $chunk;
        }
    }

    public function addEvent(SSEEvent $event): void
    {
        if ($this->response !== null) {
            $this->response->addEvent($event);
        } else {
            $this->bufferedEvents[] = $event;
        }
    }

    public function setResponse(GeminiStreamResponse $response): void
    {
        $this->response = $response;

        // Flush buffered data into the actual response object
        foreach ($this->bufferedChunks as $chunk) {
            $this->response->addChunk($chunk);
        }

        foreach ($this->bufferedEvents as $event) {
            $this->response->addEvent($event);
        }

        // Free memory
        $this->bufferedChunks = [];
        $this->bufferedEvents = [];
    }

    public function getResponse(): ?GeminiStreamResponse
    {
        return $this->response;
    }
}
