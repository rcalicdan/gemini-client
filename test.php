<?php

use Rcalicdan\GeminiClient\GeminiClient;

require 'vendor/autoload.php';

$client = new GeminiClient();

$client->prompt('Hello, Gemini! give me a joke')
    ->streamSSE([
        'messageEvent' => 'message',
    ])
    ->await();
