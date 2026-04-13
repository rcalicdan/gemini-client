<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;

/**
 * Handles SSE streaming logic for Gemini API responses
 */
class GeminiSSEStreamer
{
    private string $messageEvent;

    private ?string $doneEvent;

    private string $errorEvent;

    private ?string $progressEvent;

    private bool $includeMetadata;

    private int $chunkCount = 0;

    private int $totalLength = 0;

    private float $startTime;

    private bool $completionEmitted = false;

    /**
     *  @var array<string, mixed>
     */
    private array $customMetadata;

    /**
     *  @var callable(string, array<string, mixed>): array<string, mixed>|null
     */
    private $onBeforeEmit;

    /**
     * @param array<string, mixed> $config SSE streaming configuration
     */
    public function __construct(array $config = [])
    {
        $merged = [...$this->getDefaultConfig(), ...$config];

        $this->messageEvent = \is_string($merged['messageEvent']) ? $merged['messageEvent'] : 'message';
        $this->doneEvent = \is_string($merged['doneEvent']) ? $merged['doneEvent'] : null;
        $this->errorEvent = \is_string($merged['errorEvent']) ? $merged['errorEvent'] : 'error';
        $this->progressEvent = \is_string($merged['progressEvent']) ? $merged['progressEvent'] : null;
        $this->includeMetadata = \is_bool($merged['includeMetadata']) ? $merged['includeMetadata'] : true;
        $this->onBeforeEmit = \is_callable($merged['onBeforeEmit']) ? $merged['onBeforeEmit'] : null;

        $rawCustomMetadata = \is_array($merged['customMetadata']) ? $merged['customMetadata'] : [];
        /** @var array<string, mixed> $rawCustomMetadata */
        $this->customMetadata = $rawCustomMetadata;

        $this->startTime = microtime(true);
    }

    /**
     * Get default configuration.
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'messageEvent' => 'message',
            'doneEvent' => 'done',
            'errorEvent' => 'error',
            'progressEvent' => null,
            'includeMetadata' => true,
            'customMetadata' => [],
            'onBeforeEmit' => null,
        ];
    }

    /**
     * Handle a streaming chunk.
     */
    public function handleChunk(string $chunk, SSEEvent $event): void
    {
        if ($chunk === '') {
            return;
        }

        $this->chunkCount++;
        $this->totalLength += strlen($chunk);

        $this->emitMessageEvent($chunk);

        if ($this->progressEvent !== null) {
            $this->emitProgressEvent();
        }
    }

    /**
     * Handle stream completion.
     */
    public function handleCompletion(): void
    {
        if ($this->completionEmitted) {
            return;
        }

        $this->completionEmitted = true;

        if ($this->doneEvent !== null) {
            $this->emitDoneEvent();
        }
    }

    /**
     * Handle stream error.
     */
    public function handleError(\Throwable $error): void
    {
        $data = [
            'error' => 'Stream failed',
            'message' => $error->getMessage(),
        ];

        if ($this->onBeforeEmit !== null) {
            $data = ($this->onBeforeEmit)($this->errorEvent, $data);
        }

        $this->emitSSE($this->errorEvent, $data);
    }

    /**
     * Emit a message event for a chunk.
     */
    private function emitMessageEvent(string $chunk): void
    {
        $data = ['content' => $chunk];

        if ($this->includeMetadata) {
            $data['metadata'] = [
                'chunk' => $this->chunkCount,
                'length' => strlen($chunk),
                'totalLength' => $this->totalLength,
                ...$this->customMetadata,
            ];
        }

        if ($this->onBeforeEmit !== null) {
            $data = ($this->onBeforeEmit)($this->messageEvent, $data);
        }

        $this->emitSSE($this->messageEvent, $data);
    }

    /**
     * Emit a progress event.
     */
    private function emitProgressEvent(): void
    {
        if ($this->progressEvent === null) {
            return;
        }

        $data = [
            'chunk' => $this->chunkCount,
            'totalChunks' => $this->chunkCount,
            'length' => $this->totalLength,
        ];

        if ($this->onBeforeEmit !== null) {
            $data = ($this->onBeforeEmit)($this->progressEvent, $data);
        }

        $this->emitSSE($this->progressEvent, $data);
    }

    /**
     * Emit a completion event.
     */
    private function emitDoneEvent(): void
    {
        if ($this->doneEvent === null) {
            return;
        }

        $duration = microtime(true) - $this->startTime;

        $data = ['status' => 'complete'];

        if ($this->includeMetadata) {
            $data['metadata'] = [
                'chunks' => $this->chunkCount,
                'length' => $this->totalLength,
                'duration' => round($duration, 3),
                ...$this->customMetadata,
            ];
        }

        if ($this->onBeforeEmit !== null) {
            $data = ($this->onBeforeEmit)($this->doneEvent, $data);
        }

        $this->emitSSE($this->doneEvent, $data);
    }

    /**
     * Emit an SSE event with automatic flushing.
     *
     * @param array<string, mixed> $data
     */
    private function emitSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Get current streaming statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'chunks' => $this->chunkCount,
            'totalLength' => $this->totalLength,
            'duration' => microtime(true) - $this->startTime,
        ];
    }

    /**
     * Reset the streamer state.
     */
    public function reset(): void
    {
        $this->chunkCount = 0;
        $this->totalLength = 0;
        $this->startTime = microtime(true);
        $this->completionEmitted = false;
    }
}
