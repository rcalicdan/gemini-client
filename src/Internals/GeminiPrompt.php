<?php

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
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
     * Execute the prompt and return a GeminiResponse.
     *
     * @return PromiseInterface<GeminiResponse>
     */
    public function send(): PromiseInterface
    {
        return $this->client->generateContent($this->prompt, $this->options, $this->model);
    }

    /**
     * Execute with streaming.
     *
     * @param callable(string, SSEEvent): void $onChunk Callback receives text chunk and SSE event
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function stream(callable $onChunk, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface
    {
        return $this->client->streamGenerateContent($this->prompt, $onChunk, $this->options, $this->model, $reconnectConfig);
    }
}