<?php

declare(strict_types=1);

use function Hibla\await;

it('applies generation presets and system instructions correctly', function () {
    httpMock()->mock('POST')
        ->url('*:generateContent')
        ->expect(function ($request) {
            $json = $request->getJson();

            return $json['contents'][0]['parts'][0]['text'] === 'Analyze this code.' &&
                   $json['systemInstruction']['parts'][0]['text'] === 'You are a senior developer.' &&
                   $json['generationConfig']['temperature'] === 0.2;
        })
        ->respondJson(['candidates' => [['content' => ['parts' => [['text' => 'Analysis result']]]]]])
        ->register()
    ;

    $response = await(
        gemini()
            ->prompt('Analyze this code.')
            ->system('You are a senior developer.')
            ->precise()
            ->maxTokens(100)
            ->send()
    );

    expect($response->text())->toBe('Analysis result');
});

it('correctly handles tool/function calling signatures', function () {
    $tools = [
        ['function_declarations' => [['name' => 'get_weather', 'parameters' => ['type' => 'object']]]],
    ];

    httpMock()->mock('POST')
        ->url('*:generateContent')
        ->expect(function ($request) use ($tools) {
            return $request->getJson()['tools'] === $tools;
        })
        ->respondJson(['candidates' => [['content' => ['parts' => [['text' => 'Calling tool...']]]]]])
        ->register()
    ;

    await(gemini()->prompt('What is the weather?')->tools($tools)->send());

    httpMock()->assertRequestCount(1);
});

it('remains immutable when changing models or headers', function () {
    $base = gemini();
    $flash = $base->withModel('gemini-2.0-flash');
    $pro = $base->withModel('gemini-1.5-pro');

    expect($flash)->not->toBe($base)
        ->and($pro)->not->toBe($base)
        ->and($flash)->not->toBe($pro)
    ;

    httpMock()->mock('POST')->url('*:generateContent')->persistent()->respondJson([])->register();

    await($flash->prompt('test')->send());
    await($pro->prompt('test')->send());

    httpMock()->assertRequestCount(2);
});
