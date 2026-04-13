<?php

declare(strict_types=1);

use Hibla\HttpClient\ValueObjects\RetryConfig;

use function Hibla\await;

it('retries automatically on transient API failures', function () {
    $fastRetry = new RetryConfig(maxRetries: 3, baseDelay: 0, backoffMultiplier: 0, jitter: false);
    $client = gemini()->withRetryConfig($fastRetry);

    httpMock()->mock('POST')
        ->url('*:generateContent')
        ->statusFailuresUntilAttempt(successAttempt: 3, failureStatus: 503)
        ->register()
    ;

    $response = await($client->prompt('Testing retry')->send());

    expect($response->successful())->toBeTrue()
        ->and($response->json('attempt'))->toBe(3)
    ;

    httpMock()->assertRequestCount(3);
});

it('fails after exceeding maximum retry attempts', function () {
    $fastRetry = new RetryConfig(maxRetries: 3, baseDelay: 0, backoffMultiplier: 0, jitter: false);
    $client = gemini()->withRetryConfig($fastRetry);

    httpMock()->mock('POST')
        ->url('*:generateContent')
        ->statusFailuresUntilAttempt(successAttempt: 6, failureStatus: 500)
        ->register()
    ;

    expect(fn () => await($client->prompt('Too many fails')->send()))
        ->toThrow(Hibla\HttpClient\Exceptions\NetworkException::class)
    ;
});
