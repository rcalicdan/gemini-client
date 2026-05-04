<?php

declare(strict_types=1);

use function Hibla\await;

it('can stream generation using SSE', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse')
        ->sseWithPeriodicEvents([
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Hello ']]]]],
            ])],
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'streaming ']]]]],
            ])],
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'world!']]], 'finishReason' => 'STOP']],
            ])],
        ])
        ->dataStreamTransferLatency(0.005)
        ->register()
    ;

    $chunksReceived = [];

    $streamPromise = gemini()->prompt('Stream this')->stream(function (string $chunk) use (&$chunksReceived) {
        $chunksReceived[] = $chunk;
    });

    $streamResponse = await($streamPromise);

    expect($chunksReceived)->toBe(['Hello ', 'streaming ', 'world!'])
        ->and($streamResponse->text())->toBe('Hello streaming world!')
        ->and($streamResponse->chunkCount())->toBe(3)
    ;
});

it('handles keep-alive events properly during streaming', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:streamGenerateContent?alt=sse')
        ->sseWithKeepalive([
            ['data' => json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Data after keepalive']]],
                    'finishReason' => 'STOP',
                ]],
            ])],
        ], keepaliveCount: 2)
        ->register()
    ;

    $chunksReceived = [];

    await(
        gemini()->prompt('Keepalive test')->stream(function (string $chunk) use (&$chunksReceived) {
            $chunksReceived[] = $chunk;
        })
    );

    expect($chunksReceived)->toBe(['Data after keepalive']);
});

it('can stream formatted SSE directly to output using streamWithEvents', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/*:streamGenerateContent?alt=sse')
        ->sseWithPeriodicEvents([
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Server ']]]]],
            ])],
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Sent ']]]]],
            ])],
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'Events!']]], 'finishReason' => 'STOP']],
            ])],
        ])
        ->dataStreamTransferLatency(0.005)
        ->register()
    ;

    $output = '';
    ob_start(function ($flushedChunk) use (&$output) {
        $output .= $flushedChunk;

        return '';
    });

    $streamPromise = gemini()
        ->prompt('Test SSE Output')
        ->streamWithEvents('custom_msg', 'custom_done', true)
    ;

    $streamResponse = await($streamPromise);

    $output .= ob_get_clean();

    expect($streamResponse->text())->toBe('Server Sent Events!')
        ->and($streamResponse->chunkCount())->toBe(3)
    ;

    expect($output)->toContain('event: custom_msg')
        ->toContain('"content":"Server "')
        ->toContain('"content":"Sent "')
        ->toContain('"content":"Events!"')
    ;

    expect($output)->toContain('"metadata":{"chunk":1,"length":7,"totalLength":7}')
        ->toContain('"metadata":{"chunk":2,"length":5,"totalLength":12}')
    ;

    expect($output)->toContain('event: custom_done')
        ->toContain('"status":"complete"')
        ->toContain('"chunks":3')
        ->toContain('"length":19')
    ;
});

it('can disable metadata in streamWithEvents', function () {
    httpMock()->mock('*')
        ->url('https://generativelanguage.googleapis.com/v1beta/models/*:streamGenerateContent?alt=sse')
        ->sseWithPeriodicEvents([
            ['event' => 'message', 'data' => json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'No Metadata']]], 'finishReason' => 'STOP']],
            ])],
        ])
        ->dataStreamTransferLatency(0.005)
        ->register()
    ;

    $output = '';
    ob_start(function ($flushedChunk) use (&$output) {
        $output .= $flushedChunk;

        return '';
    });

    await(gemini()->prompt('No meta')->streamWithEvents('message', 'done', false));

    $output .= ob_get_clean();

    expect($output)->toContain('"content":"No Metadata"')
        ->not->toContain('"metadata":');
});
