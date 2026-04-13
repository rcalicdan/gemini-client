<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Rcalicdan\GeminiClient\GeminiClient;

uses()
    ->beforeEach(fn () => Http::startTesting())
    ->afterEach(fn () => Http::stopTesting())
    ->in(__DIR__)
;

function httpMock(): TestingHttpHandler
{
    return Http::getTestingHandler();
}

function gemini(?HttpClientInterface $httpClient = null): GeminiClient
{
    return new GeminiClient('fake-api-key', 'gemini-2.0-flash', $httpClient ?? Http::client());
}
