<?php

declare(strict_types=1);

use function Hibla\await;

it('can generate content from a prompt', function () {
    httpMock()->mock('POST')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent')
        ->expectJson([
            'contents' => [
                ['parts' => [['text' => 'Hello Gemini!']]],
            ],
        ])
        ->respondJson([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Hello from the mock!']]]],
            ],
            'usageMetadata' => ['totalTokenCount' => 10],
            'modelVersion' => 'gemini-2.0-flash-v1',
        ])
        ->register()
    ;

    $response = await(gemini()->prompt('Hello Gemini!')->send());

    expect($response->successful())->toBeTrue()
        ->and($response->text())->toBe('Hello from the mock!')
        ->and($response->usage()['totalTokenCount'])->toBe(10)
        ->and($response->modelVersion())->toBe('gemini-2.0-flash-v1')
    ;

    httpMock()->assertRequestCount(1);
    httpMock()->assertHeaderSent('x-goog-api-key');
});

it('can generate a single embedding', function () {
    httpMock()->mock('POST')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent')
        ->respondJson([
            'embedding' => [
                'values' => [0.1, 0.2, 0.3, 0.4],
            ],
        ])
        ->register()
    ;

    $response = await(gemini()->embed('Test document')->send());

    expect($response->values())->toBe([0.1, 0.2, 0.3, 0.4]);
});

it('can generate batch embeddings', function () {
    httpMock()->mock('POST')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:batchEmbedContents')
        ->respondJson([
            'embeddings' => [
                ['values' => [0.1, 0.2]],
                ['values' => [0.3, 0.4]],
            ],
        ])
        ->register()
    ;

    $response = await(
        gemini()->batchEmbed()
            ->add('Document 1')
            ->add('Document 2')
            ->send()
    );

    $values = $response->values();

    expect($values)->toHaveCount(2)
        ->and($values[0])->toBe([0.1, 0.2])
        ->and($values[1])->toBe([0.3, 0.4])
    ;
});

it('can fetch the models list', function () {
    httpMock()->mock('GET')
        ->url('https://generativelanguage.googleapis.com/v1beta/models')
        ->respondJson([
            'models' => [
                ['name' => 'models/gemini-2.0-flash'],
                ['name' => 'models/gemini-embedding-001'],
            ],
        ])
        ->register()
    ;

    $response = await(gemini()->listModels());

    expect($response->successful())->toBeTrue()
        ->and($response->json('models.0.name'))->toBe('models/gemini-2.0-flash')
    ;
});

it('throws on API errors seamlessly', function () {
    httpMock()->mock('POST')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent')
        ->respondWithStatus(200)
        ->respondJson([
            'error' => ['message' => 'API key not valid. Please pass a valid API key.'],
        ])
        ->register()
    ;

    expect(fn () => await(gemini()->prompt('Fail')->send())->text())
        ->toThrow(RuntimeException::class, 'API Error: API key not valid. Please pass a valid API key.')
    ;
});
