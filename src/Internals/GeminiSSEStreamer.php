<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;

/**
 * Handles SSE streaming logic for Gemini API responses
 */
class GeminiSSEStreamer
{
    private array $config;
    private int $chunkCount = 0;
    private int $totalLength = 0;
    private float $startTime;

    /**
     * @param array<string, mixed> $config SSE streaming configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
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
     *
     * @param string $chunk
     * @param SSEEvent $event
     */
    public function handleChunk(string $chunk, SSEEvent $event): void
    {
        $this->chunkCount++;
        $this->totalLength += strlen($chunk);

        $this->emitMessageEvent($chunk);

        if ($this->config['progressEvent'] !== null) {
            $this->emitProgressEvent();
        }
    }

    /**
     * Handle stream completion.
     */
    public function handleCompletion(): void
    {
        if ($this->config['doneEvent'] !== null) {
            $this->emitDoneEvent();
        }
    }

    /**
     * Handle stream error.
     *
     * @param \Throwable $error
     */
    public function handleError(\Throwable $error): void
    {
        $data = [
            'error' => 'Stream failed',
            'message' => $error->getMessage(),
        ];

        if ($this->config['onBeforeEmit'] !== null) {
            $data = $this->config['onBeforeEmit']($this->config['errorEvent'], $data);
        }

        $this->emitSSE($this->config['errorEvent'], $data);
    }

    /**
     * Emit a message event for a chunk.
     *
     * @param string $chunk
     */
    private function emitMessageEvent(string $chunk): void
    {
        $data = ['content' => $chunk];

        if ($this->config['includeMetadata']) {
            $data['metadata'] = array_merge([
                'chunk' => $this->chunkCount,
                'length' => strlen($chunk),
                'totalLength' => $this->totalLength,
            ], $this->config['customMetadata']);
        }

        if ($this->config['onBeforeEmit'] !== null) {
            $data = $this->config['onBeforeEmit']($this->config['messageEvent'], $data);
        }

        $this->emitSSE($this->config['messageEvent'], $data);
    }

    /**
     * Emit a progress event.
     */
    private function emitProgressEvent(): void
    {
        $data = [
            'chunk' => $this->chunkCount,
            'totalChunks' => $this->chunkCount,
            'length' => $this->totalLength,
        ];

        if ($this->config['onBeforeEmit'] !== null) {
            $data = ($this->config['onBeforeEmit'])($this->config['progressEvent'], $data);
        }

        $this->emitSSE($this->config['progressEvent'], $data);
    }

    /**
     * Emit a completion event.
     */
    private function emitDoneEvent(): void
    {
        $duration = microtime(true) - $this->startTime;

        $data = ['status' => 'complete'];

        if ($this->config['includeMetadata']) {
            $data['metadata'] = array_merge([
                'chunks' => $this->chunkCount,
                'length' => $this->totalLength,
                'duration' => round($duration, 3),
            ], $this->config['customMetadata']);
        }

        if ($this->config['onBeforeEmit'] !== null) {
            $data = $this->config['onBeforeEmit']($this->config['doneEvent'], $data);
        }

        $this->emitSSE($this->config['doneEvent'], $data);
    }

    /**
     * Emit an SSE event with automatic flushing.
     * 
     * @param string $event Event name
     * @param array<string, mixed> $data Event data
     */
    private function emitSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";

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
    }
}
