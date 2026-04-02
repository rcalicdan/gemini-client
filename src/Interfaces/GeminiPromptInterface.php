<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Interfaces;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

interface GeminiPromptInterface
{
    /**
     * Override the default model to use for this prompt.
     */
    public function model(string $model): static;

    /**
     * Set the system instruction.
     *
     * @param string|array<mixed> $instruction
     */
    public function system(string|array $instruction): static;

    /**
     * Set the available tools (e.g., function calling).
     *
     * @param array<mixed> $tools
     */
    public function tools(array $tools): static;

    /**
     * Set the generation temperature (0.0 to 2.0).
     */
    public function temperature(float $temperature): static;

    /**
     * Set the maximum number of tokens to generate.
     */
    public function maxTokens(int $maxTokens): static;

    /**
     * Set top-p sampling.
     */
    public function topP(float $topP): static;

    /**
     * Set top-k sampling.
     */
    public function topK(int $topK): static;

    /**
     * Quick preset: Creative writing (high temperature, high top-p).
     */
    public function creative(): static;

    /**
     * Quick preset: Precise/factual responses (low temperature, low top-p).
     */
    public function precise(): static;

    /**
     * Quick preset: Balanced generation.
     */
    public function balanced(): static;

    /**
     * Quick preset: Code generation (low temperature, moderate top-k).
     */
    public function code(): static;

    /**
     * Execute the prompt and return a complete response.
     *
     * @return PromiseInterface<GeminiResponseInterface>
     */
    public function send(): PromiseInterface;

    /**
     * Execute with streaming using a raw callback.
     *
     * @param callable(string, SSEEvent): void $onChunk
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return PromiseInterface<GeminiStreamResponseInterface>
     */
    public function stream(callable $onChunk, ?SSEReconnectConfig $reconnectConfig = null): PromiseInterface;

    /**
     * Stream with automatic SSE emission and flushing to the client browser.
     *
     * @param array<string, mixed> $config
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return PromiseInterface<GeminiStreamResponseInterface>
     */
    public function streamSSE(array $config = [], ?SSEReconnectConfig $reconnectConfig = null): PromiseInterface;

    /**
     * Simplified streamSSE with just event names.
     *
     * @param string $messageEvent Event name for message chunks
     * @param string|null $doneEvent Event name for completion (null to disable)
     * @param bool $includeMetadata Whether to include chunk/length metadata
     * @return PromiseInterface<GeminiStreamResponseInterface>
     */
    public function streamWithEvents(string $messageEvent = 'message', ?string $doneEvent = 'done', bool $includeMetadata = true): PromiseInterface;
}
