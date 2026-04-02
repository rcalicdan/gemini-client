<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\GeminiClient\Interfaces\GeminiPromptInterface;

class GeminiPrompt implements GeminiPromptInterface
{
    private GeminiHttpRequest $httpClient;
    private GeminiRequestBuilder $builder;
    private string|array $prompt;
    private string $model;
    private SSEReconnectConfig $reconnectConfig;
    private array $options = [];

    public function __construct(
        GeminiHttpRequest $httpClient,
        GeminiRequestBuilder $builder,
        string|array $prompt,
        string $defaultModel,
        SSEReconnectConfig $defaultReconnectConfig
    ) {
        $this->httpClient = $httpClient;
        $this->builder = $builder;
        $this->prompt = $prompt;
        $this->model = $defaultModel;
        $this->reconnectConfig = $defaultReconnectConfig;
    }

    /**
     * {@inheritDoc}
     */
    public function model(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function system(string|array $instruction): static
    {
        $clone = clone $this;
        $clone->options['systemInstruction'] = is_string($instruction)
            ? ['parts' => [['text' => $instruction]]]
            : $instruction;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function tools(array $tools): static
    {
        $clone = clone $this;
        $clone->options['tools'] = $tools;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function temperature(float $temperature): static
    {
        $clone = clone $this;
        $clone->options['generationConfig']['temperature'] = $temperature;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function maxTokens(int $maxTokens): static
    {
        $clone = clone $this;
        $clone->options['generationConfig']['maxOutputTokens'] = $maxTokens;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function topP(float $topP): static
    {
        $clone = clone $this;
        $clone->options['generationConfig']['topP'] = $topP;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function topK(int $topK): static
    {
        $clone = clone $this;
        $clone->options['generationConfig']['topK'] = $topK;

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function creative(): static
    {
        return $this->temperature(0.9)->topP(0.95);
    }

    /**
     * {@inheritDoc}
     */
    public function precise(): static
    {
        return $this->temperature(0.2)->topP(0.8);
    }

    /**
     * {@inheritDoc}
     */
    public function balanced(): static
    {
        return $this->temperature(0.7)->topP(0.9);
    }

    /**
     * {@inheritDoc}
     */
    public function code(): static
    {
        return $this->temperature(0.3)->topK(40);
    }

    /**
     * {@inheritDoc}
     */
    public function send(): PromiseInterface
    {
        $payload = $this->builder->buildGenerationPayload($this->prompt, $this->options);
        $url = $this->builder->buildModelUrl($this->model, 'generateContent');

        return $this->httpClient->makeRequest($url, $payload)
            ->then(fn (Response $response) => new GeminiResponse($response, $this->builder))
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function stream(callable $onChunk, ?SSEReconnectConfig $reconnectConfig = null): PromiseInterface
    {
        $payload = $this->builder->buildGenerationPayload($this->prompt, $this->options);
        $url = $this->builder->buildModelUrl($this->model, 'streamGenerateContent', ['alt' => 'sse']);

        $config = $reconnectConfig ?? $this->reconnectConfig;

        return $this->httpClient->makeStreamRequest($url, $payload, $onChunk, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function streamSSE(array $config = [], ?SSEReconnectConfig $reconnectConfig = null): PromiseInterface
    {
        $streamer = new GeminiSSEStreamer($config);

        return $this->stream(
            function (string $chunk, SSEEvent $event) use ($streamer) {
                $streamer->handleChunk($chunk, $event);
            },
            $reconnectConfig
        )->then(function ($response) use ($streamer) {
            register_shutdown_function(fn () => $streamer->handleCompletion());

            return $response;
        })->catch(function (\Throwable $error) use ($streamer) {
            $streamer->handleError($error);

            throw $error;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function streamWithEvents(string $messageEvent = 'message', ?string $doneEvent = 'done', bool $includeMetadata = true): PromiseInterface
    {
        return $this->streamSSE([
            'messageEvent' => $messageEvent,
            'doneEvent' => $doneEvent,
            'includeMetadata' => $includeMetadata,
        ]);
    }
}
