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

    /**
     * Execute with streaming and output as SSE events.
     * 
     * @param callable(string): void $outputCallback Callback to output SSE formatted string
     * @param SSEReconnectConfig|null $reconnectConfig
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamAsSSE(callable $outputCallback, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface
    {
        return $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use ($outputCallback) {
                $sse = "data: " . json_encode(['content' => $chunk]) . "\n\n";
                $outputCallback($sse);
            },
            $this->options,
            $this->model,
            $reconnectConfig
        );
    }

    /**
     * Stream and auto-flush as SSE events (for Laravel streaming responses).
     * 
     * @param bool $sendDoneEvent Whether to send a 'done' event on completion
     * @param string|null $doneEventName Custom name for completion event
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamAndFlush(
        bool $sendDoneEvent = true,
        ?string $doneEventName = 'done'
    ): CancellablePromiseInterface {
        return $this->streamAsSSE(function ($sseFormatted) {
            echo $sseFormatted;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        })->then(function ($response) use ($sendDoneEvent, $doneEventName) {
            if ($sendDoneEvent) {
                echo "event: {$doneEventName}\n";
                echo "data: " . json_encode([
                    'status' => 'complete',
                    'chunks' => $response->chunkCount(),
                    'length' => strlen($response->text())
                ]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
            return $response;
        });
    }

    /**
     * Stream with custom event types and metadata.
     * 
     * @param string $eventType Event type name (e.g., 'message', 'token')
     * @param bool $sendDoneEvent Whether to send completion event
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithEvent(
        string $eventType = 'message',
        bool $sendDoneEvent = true
    ): CancellablePromiseInterface {
        return $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use ($eventType) {
                echo "event: {$eventType}\n";
                echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            $this->options,
            $this->model
        )->then(function ($response) use ($sendDoneEvent) {
            if ($sendDoneEvent) {
                echo "event: done\n";
                echo "data: " . json_encode(['status' => 'complete']) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
            return $response;
        });
    }

    /**
     * Stream with progress updates.
     * 
     * @return CancellablePromiseInterface<GeminiStreamResponse>
     */
    public function streamWithProgress(): CancellablePromiseInterface
    {
        $chunkCount = 0;
        $totalLength = 0;

        return $this->client->streamGenerateContent(
            $this->prompt,
            function (string $chunk, SSEEvent $event) use (&$chunkCount, &$totalLength) {
                $chunkCount++;
                $totalLength += strlen($chunk);

                echo "event: message\n";
                echo "data: " . json_encode([
                    'content' => $chunk,
                    'progress' => [
                        'chunk' => $chunkCount,
                        'length' => $totalLength
                    ]
                ]) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            },
            $this->options,
            $this->model
        )->then(function ($response) use (&$chunkCount, &$totalLength) {
            echo "event: done\n";
            echo "data: " . json_encode([
                'status' => 'complete',
                'total_chunks' => $chunkCount,
                'total_length' => $totalLength
            ]) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            return $response;
        });
    }
}
