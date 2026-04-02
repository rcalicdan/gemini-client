<?php

declare(strict_types=1);

namespace Rcalicdan\GeminiClient\Internals;

use Hibla\HttpClient\Response;

/**
 * Handles request building and response parsing for Gemini API
 */
class GeminiRequestBuilder
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';
    private const API_VERSION = 'v1beta';

    /**
     * Build generation payload.
     *
     * @param string|array<mixed> $prompt
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function buildGenerationPayload(string|array $prompt, array $options): array
    {
        $contents = is_string($prompt)
            ? [['parts' => [['text' => $prompt]]]]
            : (isset($prompt['parts']) ? [$prompt] : $prompt);

        $payload = ['contents' => $contents];

        foreach (['generationConfig', 'safetySettings', 'systemInstruction', 'tools'] as $key) {
            if (isset($options[$key])) {
                $payload[$key] = $options[$key];
            }
        }

        return $payload;
    }

    /**
     * Build embedding payload.
     *
     * @param string|array<string> $content
     * @param string $taskType
     * @param string|null $title
     * @param int|null $outputDimensionality
     * @return array<string, mixed>
     */
    public function buildEmbeddingPayload(
        string|array $content,
        string $taskType,
        ?string $title = null,
        ?int $outputDimensionality = null
    ): array {
        $payload = [
            'task_type' => $taskType,
        ];

        if (is_string($content)) {
            $payload['content'] = [
                'parts' => [['text' => $content]],
            ];
        } else {
            $payload['content'] = [
                'parts' => array_map(fn($text) => ['text' => $text], $content),
            ];
        }

        if ($title !== null) {
            $payload['title'] = $title;
        }

        if ($outputDimensionality !== null) {
            $payload['outputDimensionality'] = $outputDimensionality;
        }

        return $payload;
    }

    /**
     * Build batch embedding payload.
     *
     * @param array<array{content: string, task_type?: string, title?: string}> $requests
     * @param string $defaultModel
     * @param int|null $outputDimensionality
     * @return array<string, mixed>
     */
    public function buildBatchEmbeddingPayload(
        array $requests,
        string $defaultModel,
        ?int $outputDimensionality = null
    ): array {
        $formattedRequests = [];

        foreach ($requests as $request) {
            $formattedRequest = [
                'model' => 'models/' . $defaultModel,
                'content' => [
                    'parts' => [['text' => $request['content']]],
                ],
                'task_type' => $request['task_type'] ?? 'RETRIEVAL_DOCUMENT',
            ];

            if (isset($request['title'])) {
                $formattedRequest['title'] = $request['title'];
            }

            if ($outputDimensionality !== null) {
                $formattedRequest['outputDimensionality'] = $outputDimensionality;
            }

            $formattedRequests[] = $formattedRequest;
        }

        return ['requests' => $formattedRequests];
    }

    /**
     * Build model URL.
     *
     * @param string $model
     * @param string $endpoint
     * @param array<string, string> $queryParams
     * @return string
     */
    public function buildModelUrl(string $model, string $endpoint, array $queryParams = []): string
    {
        $url = sprintf(
            '%s/%s/models/%s:%s',
            self::BASE_URL,
            self::API_VERSION,
            $model,
            $endpoint
        );

        if (! empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Build base URL for models endpoint.
     *
     * @return string
     */
    public function buildModelsUrl(): string
    {
        return sprintf('%s/%s/models', self::BASE_URL, self::API_VERSION);
    }

    /**
     * Build specific model info URL.
     *
     * @param string $model
     * @return string
     */
    public function buildModelInfoUrl(string $model): string
    {
        return sprintf('%s/%s/models/%s', self::BASE_URL, self::API_VERSION, $model);
    }

    /**
     * Extract text from generation response.
     */
    public function extractTextFromResponse(Response $response): string
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';

            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        $candidates = $data['candidates'] ?? [];

        if (empty($candidates) && isset($data[0]['candidates'])) {
            $candidates = $data[0]['candidates'];
        }

        if (empty($candidates)) {
            if (isset($data['content']['parts'])) {
                $parts = $data['content']['parts'];
                $text = '';
                foreach ($parts as $part) {
                    if (isset($part['text'])) {
                        $text .= $part['text'];
                    }
                }

                return $text;
            }

            throw new \RuntimeException('No candidates in response. Response structure: ' . json_encode(array_keys($data)));
        }

        $content = $candidates[0]['content'] ?? [];
        $parts = $content['parts'] ?? [];

        if (empty($parts)) {
            throw new \RuntimeException('No parts in candidate content');
        }

        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (empty($text)) {
            throw new \RuntimeException('No text content found in response parts');
        }

        return $text;
    }

    /**
     * Extract embeddings from response.
     *
     * @return array<float>|array<array<float>>
     */
    public function extractEmbeddingsFromResponse(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid response format');
        }

        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';
            throw new \RuntimeException('API Error: ' . $errorMessage);
        }

        if (isset($data['embedding']['values'])) {
            return $data['embedding']['values'];
        }

        if (isset($data['embeddings'])) {
            return array_map(fn($emb) => $emb['values'] ?? [], $data['embeddings']);
        }

        throw new \RuntimeException('No embeddings found in response');
    }

    /**
     * Parse SSE event data and extract text chunks.
     *
     * @param string $data
     * @return array<string>
     */
    public function parseSSEData(string $data): array
    {
        $chunks = [];
        $parsed = json_decode($data, true);

        if (! is_array($parsed)) {
            return $chunks;
        }

        $candidates = $parsed['candidates'] ?? [];
        foreach ($candidates as $candidate) {
            $content = $candidate['content'] ?? [];
            $parts = $content['parts'] ?? [];

            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }
        }

        return $chunks;
    }

    /**
     * Calculate cosine similarity between two vectors.
     * Automatically L2-normalizes both vectors first, making it safe
     * for any outputDimensionality including reduced dimensions.
     *
     * @param array<float> $a
     * @param array<float> $b
     * @return float Value between -1.0 and 1.0 (1.0 = identical direction)
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new \InvalidArgumentException('Vectors must have the same length');
        }

        $a = $this->l2Normalize($a);
        $b = $this->l2Normalize($b);

        $similarity = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $similarity += $a[$i] * $b[$i];
        }

        return $similarity;
    }

    /**
     * Normalize a vector to unit length (L2 normalization).
     * Required before cosine similarity when using outputDimensionality < 3072,
     * since the API only pre-normalizes full 3072-dimensional embeddings.
     *
     * @param array<float> $vector
     * @return array<float>
     */
    public function l2Normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));

        if ($magnitude == 0.0) {
            return $vector;
        }

        return array_map(fn($v) => $v / $magnitude, $vector);
    }
}
