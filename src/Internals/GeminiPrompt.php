<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\GeminiClient;

use function Hibla\async;
use function Hibla\await;

/**
 * Fluent interface for building and executing Gemini prompts
 */
class GeminiPrompt
{
    private GeminiClient $client;
    private string|array $prompt;
    private array $options = [];
    private ?string $model = null;

    public function __construct(GeminiClient $client, string|array $prompt)
    {
        $this->client = $client;
        $this->prompt = $prompt;
    }

    /**
     * Set the model to use.
     */
    public function model(string $model): self
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    /**
     * Set generation config.
     *
     * @param array<string, mixed> $config
     */
    public function config(array $config): self
    {
        $clone = clone $this;
        $clone->options['generationConfig'] = $config;
        return $clone;
    }

    /**
     * Set safety settings.
     *
     * @param array<mixed> $settings
     */
    public function safety(array $settings): self
    {
        $clone = clone $this;
        $clone->options['safetySettings'] = $settings;
        return $clone;
    }

    /**
     * Set system instruction.
     *
     * @param string|array<mixed> $instruction
     */
    public function system(string|array $instruction): self
    {
        $clone = clone $this;
        $clone->options['systemInstruction'] = is_string($instruction)
            ? ['parts' => [['text' => $instruction]]]
            : $instruction;
        return $clone;
    }

    /**
     * Set tools.
     *
     * @param array<mixed> $tools
     */
    public function tools(array $tools): self
    {
        $clone = clone $this;
        $clone->options['tools'] = $tools;
        return $clone;
    }

    /**
     * Set temperature.
     */
    public function temperature(float $temperature): self
    {
        $clone = clone $this;
        $clone->options['generationConfig']['temperature'] = $temperature;
        return $clone;
    }

    /**
     * Set max tokens.
     */
    public function maxTokens(int $maxTokens): self
    {
        $clone = clone $this;
        $clone->options['generationConfig']['maxOutputTokens'] = $maxTokens;
        return $clone;
    }

    /**
     * Set top-p sampling.
     */
    public function topP(float $topP): self
    {
        $clone = clone $this;
        $clone->options['generationConfig']['topP'] = $topP;
        return $clone;
    }

    /**
     * Set top-k sampling.
     */
    public function topK(int $topK): self
    {
        $clone = clone $this;
        $clone->options['generationConfig']['topK'] = $topK;
        return $clone;
    }

    /**
     * Quick preset: Creative writing
     */
    public function creative(): self
    {
        return $this->temperature(0.9)->topP(0.95);
    }

    /**
     * Quick preset: Precise/factual responses
     */
    public function precise(): self
    {
        return $this->temperature(0.2)->topP(0.8);
    }

    /**
     * Quick preset: Balanced
     */
    public function balanced(): self
    {
        return $this->temperature(0.7)->topP(0.9);
    }

    /**
     * Quick preset: Code generation
     */
    public function code(): self
    {
        return $this->temperature(0.3)->topK(40);
    }

    /**
     * Set multiple generation options at once
     */
    public function with(array $options): self
    {
        $clone = clone $this;
        foreach ($options as $key => $value) {
            if (method_exists($clone, $key)) {
                $clone = $clone->$key($value);
            }
        }
        return $clone;
    }

    /**
     * Execute the prompt and return a GeminiResponse.
     *
     * @return PromiseInterface<GeminiResponse>
     */
    public function send(): PromiseInterface
    {
        return $this->client->generateContent($this->prompt, $this->options, $this->model);
    }

    /**
     * Execute with streaming (raw callback, no auto SSE).
     *
     * @param callable(string, SSEEvent): void $onChunk Callback receives text chunk and SSE event
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function stream(callable $onChunk, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface
    {
        return $this->client->streamGenerateContent($this->prompt, $onChunk, $this->options, $this->model, $reconnectConfig);
    }

    /**
     * Stream with automatic SSE emission and flushing.
     * Automatically emits 'message' events for chunks and 'done' event on completion.
     * 
     * @param array<string, mixed> $config Configuration options:
     *   - 'messageEvent' (string): Event name for chunks (default: 'message')
     *   - 'doneEvent' (string|null): Event name for completion (default: 'done', null to disable)
     *   - 'errorEvent' (string): Event name for errors (default: 'error')
     *   - 'progressEvent' (string|null): Event name for progress updates (default: null, set to enable)
     *   - 'includeMetadata' (bool): Include metadata in events (default: true)
     *   - 'customMetadata' (array): Additional metadata to include in events
     *   - 'onBeforeEmit' (callable|null): Callback before emitting each event: fn(string $event, array $data): array
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamSSE(
        array $config = [],
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        // Default configuration
        $messageEvent = $config['messageEvent'] ?? 'message';
        $doneEvent = $config['doneEvent'] ?? 'done';
        $errorEvent = $config['errorEvent'] ?? 'error';
        $progressEvent = $config['progressEvent'] ?? null;
        $includeMetadata = $config['includeMetadata'] ?? true;
        $customMetadata = $config['customMetadata'] ?? [];
        $onBeforeEmit = $config['onBeforeEmit'] ?? null;

        // Tracking variables
        $chunkCount = 0;
        $totalLength = 0;
        $startTime = microtime(true);

        // Use the underlying stream method which returns CancellablePromiseInterface
        return $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use (
                $messageEvent,
                $progressEvent,
                $includeMetadata,
                $customMetadata,
                $onBeforeEmit,
                &$chunkCount,
                &$totalLength,
                &$startTime,
                $doneEvent,
                $errorEvent
            ) {
                $chunkCount++;
                $totalLength += strlen($chunk);

                // Build message event data
                $data = ['content' => $chunk];

                if ($includeMetadata) {
                    $data['metadata'] = array_merge([
                        'chunk' => $chunkCount,
                        'length' => strlen($chunk),
                        'totalLength' => $totalLength,
                    ], $customMetadata);
                }

                // Allow modification before emission
                if ($onBeforeEmit !== null) {
                    $data = $onBeforeEmit($messageEvent, $data);
                }

                // Emit message event
                $this->emitSSE($messageEvent, $data);

                // Emit progress event if enabled
                if ($progressEvent !== null) {
                    $progressData = [
                        'chunk' => $chunkCount,
                        'totalChunks' => $chunkCount,
                        'length' => $totalLength,
                    ];

                    if ($onBeforeEmit !== null) {
                        $progressData = $onBeforeEmit($progressEvent, $progressData);
                    }

                    $this->emitSSE($progressEvent, $progressData);
                }
            },
            $this->options,
            $this->model,
            $reconnectConfig
        )->then(function($response) use (
            $doneEvent,
            $includeMetadata,
            $customMetadata,
            $onBeforeEmit,
            $chunkCount,
            $totalLength,
            $startTime
        ) {
            // Emit completion event if enabled
            if ($doneEvent !== null) {
                $duration = microtime(true) - $startTime;
                
                $data = ['status' => 'complete'];

                if ($includeMetadata) {
                    $data['metadata'] = array_merge([
                        'chunks' => $chunkCount,
                        'length' => $totalLength,
                        'duration' => round($duration, 3),
                    ], $customMetadata);
                }

                if ($onBeforeEmit !== null) {
                    $data = $onBeforeEmit($doneEvent, $data);
                }

                $this->emitSSE($doneEvent, $data);
            }

            return $response;
        })->catch(function(\Throwable $error) use ($errorEvent, $onBeforeEmit) {
            // Emit error event
            $data = [
                'error' => 'Stream failed',
                'message' => $error->getMessage(),
            ];

            if ($onBeforeEmit !== null) {
                $data = $onBeforeEmit($errorEvent, $data);
            }

            $this->emitSSE($errorEvent, $data);

            throw $error;
        });
    }

    /**
     * Simplified streamSSE with just event names.
     * Quick method for basic SSE streaming with custom event names.
     * 
     * @param string $messageEvent Event name for message chunks
     * @param string|null $doneEvent Event name for completion (null to disable)
     * @param bool $includeMetadata Whether to include chunk/length metadata
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithEvents(
        string $messageEvent = 'message',
        ?string $doneEvent = 'done',
        bool $includeMetadata = true
    ): CancellablePromiseInterface {
        return $this->streamSSE([
            'messageEvent' => $messageEvent,
            'doneEvent' => $doneEvent,
            'includeMetadata' => $includeMetadata,
        ]);
    }

    /**
     * Stream with progress updates enabled.
     * 
     * @param string $progressEvent Event name for progress updates
     * @param string $messageEvent Event name for message chunks
     * @param string|null $doneEvent Event name for completion
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithProgress(
        string $progressEvent = 'progress',
        string $messageEvent = 'message',
        ?string $doneEvent = 'done'
    ): CancellablePromiseInterface {
        return $this->streamSSE([
            'messageEvent' => $messageEvent,
            'doneEvent' => $doneEvent,
            'progressEvent' => $progressEvent,
        ]);
    }

    /**
     * Stream with custom metadata in all events.
     * 
     * @param array<string, mixed> $metadata Custom metadata to include
     * @param string $messageEvent Event name for message chunks
     * @param string|null $doneEvent Event name for completion
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithMetadata(
        array $metadata,
        string $messageEvent = 'message',
        ?string $doneEvent = 'done'
    ): CancellablePromiseInterface {
        return $this->streamSSE([
            'messageEvent' => $messageEvent,
            'doneEvent' => $doneEvent,
            'customMetadata' => $metadata,
        ]);
    }

    /**
     * Legacy method: Stream with custom event type.
     * 
     * @deprecated Use streamWithEvents() instead
     * @param string $eventType Event type name (e.g., 'message', 'token')
     * @param bool $sendDoneEvent Send completion event
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithEvent(
        string $eventType = 'message',
        bool $sendDoneEvent = true
    ): CancellablePromiseInterface {
        return $this->streamWithEvents(
            $eventType,
            $sendDoneEvent ? 'done' : null,
            false
        );
    }

    /**
     * Legacy method: Stream and auto-flush as SSE events.
     * 
     * @deprecated Use streamSSE() instead
     * @param bool $sendDoneEvent Whether to send a 'done' event on completion
     * @param string|null $doneEventName Custom name for completion event
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamAndFlush(
        bool $sendDoneEvent = true,
        ?string $doneEventName = 'done'
    ): CancellablePromiseInterface {
        return $this->streamSSE([
            'doneEvent' => $sendDoneEvent ? $doneEventName : null,
        ]);
    }

    /**
     * Legacy method: Stream with progress updates (old implementation).
     * 
     * @deprecated Use streamWithProgress() instead
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithProgressUpdates(): CancellablePromiseInterface
    {
        return $this->streamWithProgress();
    }

    /**
     * Legacy method: Execute with streaming and output as SSE events.
     * 
     * @deprecated Use streamSSE() instead
     * @param callable(string): void $outputCallback Callback to output SSE formatted string
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamAsSSE(callable $outputCallback, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface
    {
        return $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use ($outputCallback) {
                $sse = "event: message\n";
                $sse .= "data: " . json_encode(['content' => $chunk]) . "\n\n";
                $outputCallback($sse);
            },
            $this->options,
            $this->model,
            $reconnectConfig
        );
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
}