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
        $this->model = $model;
        return $this;
    }

    /**
     * Set generation config.
     *
     * @param array<string, mixed> $config
     */
    public function config(array $config): self
    {
        $this->options['generationConfig'] = $config;
        return $this;
    }

    /**
     * Set safety settings.
     *
     * @param array<mixed> $settings
     */
    public function safety(array $settings): self
    {
        $this->options['safetySettings'] = $settings;
        return $this;
    }

    /**
     * Set system instruction.
     *
     * @param string|array<mixed> $instruction
     */
    public function system(string|array $instruction): self
    {
        $this->options['systemInstruction'] = is_string($instruction)
            ? ['parts' => [['text' => $instruction]]]
            : $instruction;
        return $this;
    }

    /**
     * Set tools.
     *
     * @param array<mixed> $tools
     */
    public function tools(array $tools): self
    {
        $this->options['tools'] = $tools;
        return $this;
    }

    /**
     * Set temperature.
     */
    public function temperature(float $temperature): self
    {
        $this->options['generationConfig']['temperature'] = $temperature;
        return $this;
    }

    /**
     * Set max tokens.
     */
    public function maxTokens(int $maxTokens): self
    {
        $this->options['generationConfig']['maxOutputTokens'] = $maxTokens;
        return $this;
    }

    /**
     * Set top-p sampling.
     */
    public function topP(float $topP): self
    {
        $this->options['generationConfig']['topP'] = $topP;
        return $this;
    }

    /**
     * Set top-k sampling.
     */
    public function topK(int $topK): self
    {
        $this->options['generationConfig']['topK'] = $topK;
        return $this;
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