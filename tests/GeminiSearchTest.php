<?php

declare(strict_types=1);

use function Hibla\await;

it('can perform a local semantic search across multiple documents', function () {
    httpMock()->mock('POST')
        ->url('*:embedContent')
        ->expect(fn ($r) => $r->getJson()['task_type'] === 'RETRIEVAL_QUERY')
        ->respondJson(['embedding' => ['values' => [1.0, 0.0, 0.0]]])
        ->register()
    ;

    httpMock()->mock('POST')
        ->url('*:embedContent')
        ->expect(fn ($r) => str_contains($r->getJson()['content']['parts'][0]['text'], 'Laravel'))
        ->respondJson(['embedding' => ['values' => [0.9, 0.1, 0.0]]])
        ->register()
    ;

    httpMock()->mock('POST')
        ->url('*:embedContent')
        ->expect(fn ($r) => str_contains($r->getJson()['content']['parts'][0]['text'], 'pizza'))
        ->respondJson(['embedding' => ['values' => [0.0, 0.0, 1.0]]])
        ->register()
    ;

    $results = await(
        gemini()->search('PHP framework')
            ->documents([
                'Laravel is a PHP framework',
                'I like pizza',
            ])
            ->send()
    );

    expect($results)->toHaveCount(2)
        ->and($results[0]['text'])->toBe('Laravel is a PHP framework')
        ->and($results[0]['similarity'])->toBeGreaterThan(0.8)
    ;
});
