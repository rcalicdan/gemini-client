<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\Defer\Defer;
use Rcalicdan\GeminiClient\GeminiClient;

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
        $streamer = new GeminiSSEStreamer($config);

        $promise = $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use ($streamer) {
                $streamer->handleChunk($chunk, $event);
            },
            $this->options,
            $this->model,
            $reconnectConfig
        );

        return $promise
            ->then(function ($response) use ($streamer) {
                Defer::global(function () use ($streamer) {
                    $streamer->handleCompletion();
                });

                return $response;
            })
            ->catch(function (\Throwable $error) use ($streamer) {
                $streamer->handleError($error);
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
}
