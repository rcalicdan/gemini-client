<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

use function Hibla\await;

it('can stream generation using SSE', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse')
        ->sseWithPeriodicEvents([
            ['event' => 'message', 'data' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hello ']]]]]])],
            ['event' => 'message', 'data' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'streaming ']]]]]])],
            ['event' => 'message', 'data' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'world!']]]]]])],
        ])
        ->dataStreamTransferLatency(0.005)
        ->register()
    ;

    $chunksReceived = [];
    $finished = new Promise();

    $streamPromise = gemini()->prompt('Stream this')->stream(function (string $chunk) use (&$chunksReceived, $finished) {
        $chunksReceived[] = $chunk;

        if (count($chunksReceived) === 3) {
            $finished->resolve(true);
        }
    });

    $streamResponse = await($streamPromise);

    await($finished);

    expect($chunksReceived)->toBe(['Hello ', 'streaming ', 'world!'])
        ->and($streamResponse->text())->toBe('Hello streaming world!')
        ->and($streamResponse->chunkCount())->toBe(3)
    ;

    httpMock()->assertSSEConnectionMade('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse');
});

it('handles keep-alive events properly during streaming', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse')
        ->sseWithKeepalive([
            ['data' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Data after keepalive']]]]]])],
        ], keepaliveCount: 2)
        ->register()
    ;

    $chunksReceived = [];
    $finished = new Promise();

    gemini()->prompt('Keepalive test')->stream(function (string $chunk) use (&$chunksReceived, $finished) {
        $chunksReceived[] = $chunk;
        $finished->resolve(true);
    });

    await($finished);

    expect($chunksReceived)->toBe(['Data after keepalive']);
});
