<?php

declare(strict_types=1);

use Hibla\HttpClient\Response;
use Rcalicdan\GeminiClient\Internals\GeminiRequestBuilder;

it('correctly normalizes vectors to L2 unit length', function () {
    $builder = new GeminiRequestBuilder();
    $vector = [3.0, 4.0];

    expect($builder->l2Normalize($vector))->toBe([0.6, 0.8]);
});

it('handles zero vectors safely during normalization', function () {
    $builder = new GeminiRequestBuilder();
    $vector = [0.0, 0.0];

    expect($builder->l2Normalize($vector))->toBe([0.0, 0.0]);
});

it('computes correct cosine similarity between vectors', function () {
    $builder = new GeminiRequestBuilder();

    $vectorA = [1.0, 0.0];
    $vectorB = [1.0, 0.0];
    $vectorC = [0.0, 1.0];
    $vectorD = [-1.0, 0.0];

    expect($builder->cosineSimilarity($vectorA, $vectorB))->toBe(1.0)
        ->and($builder->cosineSimilarity($vectorA, $vectorC))->toBe(0.0)
        ->and($builder->cosineSimilarity($vectorA, $vectorD))->toBe(-1.0)
    ;
});

it('throws if computing similarity on vectors of different lengths', function () {
    $builder = new GeminiRequestBuilder();

    expect(fn () => $builder->cosineSimilarity([1.0, 0.0], [1.0, 0.0, 0.5]))
        ->toThrow(InvalidArgumentException::class, 'Vectors must have the same length')
    ;
});

it('builds generation payload properly', function () {
    $builder = new GeminiRequestBuilder();

    $payload = $builder->buildGenerationPayload('Hello', [
        'generationConfig' => ['temperature' => 0.5],
        'systemInstruction' => ['parts' => [['text' => 'Be helpful']]],
    ]);

    expect($payload['contents'][0]['parts'][0]['text'])->toBe('Hello')
        ->and($payload['generationConfig']['temperature'])->toBe(0.5)
        ->and($payload['systemInstruction']['parts'][0]['text'])->toBe('Be helpful')
    ;
});

it('extracts text from a valid Gemini response', function () {
    $builder = new GeminiRequestBuilder();

    $json = json_encode([
        'candidates' => [
            ['content' => ['parts' => [['text' => 'Extracted text successfully!']]]],
        ],
    ]);

    $response = new Response($json, 200, ['Content-Type' => 'application/json']);

    expect($builder->extractTextFromResponse($response))->toBe('Extracted text successfully!');
});

it('parses SSE data into chunks', function () {
    $builder = new GeminiRequestBuilder();

    $jsonString = json_encode([
        'candidates' => [
            ['content' => ['parts' => [['text' => 'chunk 1']]]],
            ['content' => ['parts' => [['text' => 'chunk 2']]]],
        ],
    ]);

    expect($builder->parseSSEData($jsonString))->toBe(['chunk 1', 'chunk 2']);
});
