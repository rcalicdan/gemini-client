<?php

use Rcalicdan\GeminiClient\GeminiClient;
use function Hibla\await;

require 'vendor/autoload.php';

$gemini = new GeminiClient();

await(
    $gemini->prompt('Write a short story about a robot')
        ->streamWithEvents(messageEvent: 'message', doneEvent: 'done')
);