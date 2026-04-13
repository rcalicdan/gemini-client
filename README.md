# Gemini PHP Client

**An async-first, fluent PHP client for the Google Gemini API built on the Hibla HTTP Client.**

Supports content generation, streaming (SSE), embeddings, batch embeddings, and semantic search — all non-blocking, built on top of [Hibla Promise](https://github.com/hiblaphp/promise) and [Hibla Event Loop](https://github.com/hiblaphp/event-loop).

[![Latest Release](https://img.shields.io/github/release/rcalicdan/gemini-client.svg?style=flat-square)](https://github.com/rcalicdan/gemini-client/releases)
[![Tests](https://github.com/rcalicdan/gemini-client/actions/workflows/test.yml/badge.svg)](https://github.com/rcalicdan/gemini-client/actions/workflows/test.yml)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE.md)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)

**Entry points**
- [Instantiation](#instantiation)
- [API key resolution](#api-key-resolution)
- [Dependency injection](#dependency-injection)

**Content generation**
- [Basic prompts](#basic-prompts)
- [System instructions](#system-instructions)
- [Generation presets](#generation-presets)
- [Fine-grained generation config](#fine-grained-generation-config)
- [Tools and function calling](#tools-and-function-calling)
- [Overriding the model per-request](#overriding-the-model-per-request)

**Working with responses**
- [Response inspection](#response-inspection)
- [Candidates and usage metadata](#candidates-and-usage-metadata)
- [Error handling](#error-handling)

**Streaming**
- [Raw streaming](#raw-streaming)
- [Browser SSE streaming](#browser-sse-streaming)
- [Simplified event streaming](#simplified-event-streaming)
- [Stream response object](#stream-response-object)
- [SSE reconnection](#sse-reconnection)

**Embeddings**
- [Single embedding](#single-embedding)
- [Batch embeddings](#batch-embeddings)
- [Output dimensionality](#output-dimensionality)
- [Task types](#task-types)

**Semantic search**
- [Basic search](#basic-search)
- [Custom model and dimensionality](#custom-model-and-dimensionality)

**Transport configuration**
- [Retry configuration](#retry-configuration)
- [Custom HTTP client](#custom-http-client)
- [Default headers](#default-headers)

**Model management**
- [Listing models](#listing-models)
- [Getting model info](#getting-model-info)

**Reference**
- [API Reference](#api-reference)
  - [`GeminiClientInterface`](#geminiclientinterface)
  - [`GeminiPromptInterface`](#geminipromptinterface)
  - [`GeminiResponseInterface`](#geminiresponseinterface)
  - [`GeminiStreamResponseInterface`](#geministreamresponseinterface)
  - [`GeminiEmbeddingInterface`](#geminiembeddinginterface)
  - [`GeminiBatchEmbeddingInterface`](#geminibatchembeddinginterface)
  - [`GeminiSearchInterface`](#geminisearchinterface)
  - [`GeminiEmbeddingResponseInterface`](#geminiembeddingresponseinterface)
- [Exceptions](#exceptions)

---

## Installation

> **Note:** This library is still in development and not yet feature-stable.

```bash
composer require rcalicdan/gemini-client
```

**Requirements:**
- PHP 8.4+
- `hiblaphp/http-client`
- `rcalicdan/config-loader`

---

## Quick start

```php
use Rcalicdan\GeminiClient\GeminiClient;
use function Hibla\await;

$gemini = new GeminiClient(apiKey: 'YOUR_API_KEY');

// Basic generation
$response = await($gemini->prompt('Explain quantum entanglement in one paragraph')->send());
echo $response->text();

// Streaming to the browser
await(
    $gemini->prompt('Write a short story about a robot')
        ->streamWithEvents(messageEvent: 'message', doneEvent: 'done')
);

// Embedding
$response = await($gemini->embed('Hello world')->send());
$vector = $response->values(); // array<float>

// Semantic search
$results = await(
    $gemini->search('What is machine learning?')
        ->documents([
            'Machine learning is a subset of artificial intelligence.',
            'PHP is a general-purpose scripting language.',
            'Neural networks are inspired by the human brain.',
        ])
        ->send()
);
// $results sorted by similarity: [['text' => ..., 'similarity' => ..., 'index' => ...], ...]
```

---

## How it works

`GeminiClient` is an **immutable fluent builder**. Every `with*()` method returns a new clone, so a shared base instance can safely derive multiple independent configurations without side effects.

Terminal methods (`send()`, `stream()`, `streamSSE()`, `streamWithEvents()`) return a `PromiseInterface` that resolves once the operation completes. Because everything is promise-based, multiple requests run concurrently under the same event loop:

```php
use Hibla\Promise\Promise;
use function Hibla\await;

[$summary, $translation] = await(Promise::all([
    $gemini->prompt('Summarize the PHP 8.5 release notes')->send(),
    $gemini->prompt('Translate "Hello World" to Japanese')->send(),
]));

echo $summary->text();
echo $translation->text();
```

---

## Entry points

### Instantiation

```php
use Rcalicdan\GeminiClient\GeminiClient;

// API key from argument
$gemini = new GeminiClient(apiKey: 'YOUR_API_KEY');

// API key from .env (GEMINI_API_KEY) via rcalicdan/config-loader
$gemini = new GeminiClient();

// With a default model override
$gemini = new GeminiClient(apiKey: 'YOUR_API_KEY', model: 'gemini-2.0-flash');
```

### API key resolution

The constructor resolves the API key in the following order:

1. The `$apiKey` argument if provided.
2. The `GEMINI_API_KEY` environment variable via `rcalicdan/config-loader`.

If neither is present the key defaults to an empty string and all requests will fail with a 403 from the API.

### Dependency injection

Because `GeminiClient` is immutable, a single pre-configured instance is safe to share across your entire application:

```php
use Rcalicdan\GeminiClient\GeminiClient;
use Rcalicdan\GeminiClient\Interfaces\GeminiClientInterface;

$container->singleton(GeminiClientInterface::class, function () {
    return (new GeminiClient(apiKey: config('gemini.key')))
        ->withModel('gemini-2.0-flash')
        ->withHeaders(['X-App-Id' => 'my-app']);
});
```

Then inject `GeminiClientInterface` wherever you need it:

```php
class ContentService
{
    public function __construct(private readonly GeminiClientInterface $gemini) {}

    public function summarize(string $text): PromiseInterface
    {
        return $this->gemini
            ->prompt("Summarize the following:\n\n{$text}")
            ->precise()
            ->send();
    }
}
```

---

## Content generation

### Basic prompts

```php
$response = await($gemini->prompt('What is the speed of light?')->send());
echo $response->text();

// Structured content (multi-turn / multi-part)
$response = await(
    $gemini->prompt([
        ['parts' => [['text' => 'You are a helpful assistant.']]],
        ['parts' => [['text' => 'What is the capital of France?']]],
    ])->send()
);
echo $response->text();
```

### System instructions

```php
// String shorthand
$response = await(
    $gemini->prompt('Write a product description for a coffee mug.')
        ->system('You are a professional copywriter. Be concise and persuasive.')
        ->send()
);

// Structured instruction
$response = await(
    $gemini->prompt('Explain recursion.')
        ->system(['parts' => [['text' => 'Explain everything as if to a 10-year-old.']]])
        ->send()
);
```

### Generation presets

Four built-in presets tune temperature and sampling for common use cases:

```php
$gemini->prompt($prompt)->creative(); // temperature: 0.9, topP: 0.95
$gemini->prompt($prompt)->balanced(); // temperature: 0.7, topP: 0.90
$gemini->prompt($prompt)->precise();  // temperature: 0.2, topP: 0.80
$gemini->prompt($prompt)->code();     // temperature: 0.3, topK: 40
```

### Fine-grained generation config

All configuration methods return a new clone and can be freely chained:

```php
$response = await(
    $gemini->prompt('Generate a haiku about the ocean.')
        ->temperature(0.8)
        ->maxTokens(100)
        ->topP(0.9)
        ->topK(50)
        ->send()
);
```

| Method | Type | Range | Description |
|--------|------|-------|-------------|
| `temperature(float)` | `float` | 0.0 – 2.0 | Randomness of output |
| `maxTokens(int)` | `int` | > 0 | Maximum output tokens |
| `topP(float)` | `float` | 0.0 – 1.0 | Nucleus sampling threshold |
| `topK(int)` | `int` | > 0 | Top-K sampling |

### Tools and function calling

Pass a Gemini-compatible tools array to enable function calling:

```php
$tools = [
    [
        'functionDeclarations' => [
            [
                'name' => 'get_weather',
                'description' => 'Get the current weather for a location.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'location' => ['type' => 'STRING', 'description' => 'City name'],
                    ],
                    'required' => ['location'],
                ],
            ],
        ],
    ],
];

$response = await(
    $gemini->prompt("What's the weather in Manila?")
        ->tools($tools)
        ->send()
);

$candidate = $response->candidate();
// Inspect $candidate['content']['parts'] for functionCall parts
```

### Overriding the model per-request

The default model set on the client can be overridden for any individual prompt:

```php
$gemini = new GeminiClient(apiKey: $key); // defaults to gemini-flash-latest

$response = await(
    $gemini->prompt('Write a sonnet.')
        ->model('gemini-2.5-flash')
        ->send()
);
```

---

## Working with responses

### Response inspection

```php
$response = await($gemini->prompt('Tell me a joke.')->send());

$text    = $response->text();        // extracted text string
$status  = $response->status();      // int HTTP status code
$headers = $response->headers();     // array<string, string>
$json    = $response->json();        // full decoded response array
$ok      = $response->successful();  // true for 2xx

// Dot-notation access into the JSON body
$finishReason = $response->json('candidates.0.finishReason');
```

### Candidates and usage metadata

```php
$response = await($gemini->prompt('List three planets.')->send());

// First candidate
$candidate = $response->candidate();
// ['content' => ['parts' => [...], 'role' => 'model'], 'finishReason' => 'STOP', ...]

// All candidates
$candidates = $response->candidates();

// Token usage
$usage = $response->usage();
// ['promptTokenCount' => 12, 'candidatesTokenCount' => 40, 'totalTokenCount' => 52]

// The specific model version used
$version = $response->modelVersion(); // e.g. 'gemini-2.0-flash-001'
```

### Error handling

`GeminiClient` does not throw on 4xx/5xx HTTP responses. A completed exchange always resolves the promise with a response object. API-level errors embedded in the JSON body (`error.message`) are surfaced when you call `text()` or `json()`:

```php
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Exceptions\TimeoutException;

try {
    $response = await($gemini->prompt('Hello')->send());

    if (! $response->successful()) {
        echo "HTTP error: " . $response->status();
    }

    echo $response->text(); // throws RuntimeException on API-level errors
} catch (\RuntimeException $e) {
    echo "API error: " . $e->getMessage(); // e.g. "API Error: API_KEY_INVALID"
} catch (TimeoutException $e) {
    echo "Timed out after " . $e->getTimeout() . "s";
} catch (NetworkException $e) {
    echo "Network failure: " . $e->getMessage();
}
```

---

## Streaming

### Raw streaming

`stream()` accepts a callable that receives each decoded text chunk and the raw `SSEEvent` as they arrive. It returns a `PromiseInterface<GeminiStreamResponseInterface>` that resolves once the stream closes:

```php
use Hibla\HttpClient\SSE\SSEEvent;

$response = await(
    $gemini->prompt('Write a detailed essay on the history of the internet.')
        ->stream(function (string $chunk, SSEEvent $event) {
            echo $chunk;
            flush();
        })
);

echo "\n\n--- Complete ---\n";
echo "Total chunks: " . $response->chunkCount();
echo "Full text: " . $response->text();
```

### Browser SSE streaming

`streamSSE()` handles the full browser SSE protocol automatically — it formats chunks as `event: ... / data: ...` pairs, calls `ob_flush()` and `flush()` after every chunk, and emits a final completion event when the stream closes.

Set your response headers before calling it:

```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // important for nginx

await(
    $gemini->prompt('Explain async PHP in detail.')
        ->streamSSE([
            'messageEvent'    => 'message',
            'doneEvent'       => 'done',
            'errorEvent'      => 'error',
            'includeMetadata' => true,
        ])
);
```

Each `message` event delivers a JSON payload:

```json
{
  "content": "chunk of text...",
  "metadata": {
    "chunk": 3,
    "length": 42,
    "totalLength": 150
  }
}
```

The final `done` event delivers:

```json
{
  "status": "complete",
  "metadata": {
    "chunks": 18,
    "length": 834,
    "duration": 2.341
  }
}
```

**Configuration options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `messageEvent` | `string` | `'message'` | SSE event name for text chunks |
| `doneEvent` | `string\|null` | `'done'` | SSE event name for completion (`null` to disable) |
| `errorEvent` | `string` | `'error'` | SSE event name for errors |
| `progressEvent` | `string\|null` | `null` | Optional SSE event name for progress ticks |
| `includeMetadata` | `bool` | `true` | Whether to include chunk/length metadata |
| `customMetadata` | `array` | `[]` | Extra fields merged into every metadata payload |
| `onBeforeEmit` | `callable\|null` | `null` | Callback to transform the data payload before emission |

**Custom metadata and transformation:**

```php
await(
    $gemini->prompt('Write a poem.')
        ->streamSSE([
            'customMetadata' => ['requestId' => $requestId],
            'onBeforeEmit'   => function (string $event, array $data): array {
                $data['timestamp'] = microtime(true);
                return $data;
            },
        ])
);
```

### Simplified event streaming

`streamWithEvents()` is a concise wrapper around `streamSSE()` for the common case where you only need to name the events:

```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

await(
    $gemini->prompt('Tell me about PHP 8.5 features.')
        ->streamWithEvents(
            messageEvent:    'message',
            doneEvent:       'done',     // null to suppress
            includeMetadata: false,
        )
);
```

### Stream response object

The promise from `stream()`, `streamSSE()`, and `streamWithEvents()` resolves to a `GeminiStreamResponseInterface`:

```php
$response = await($gemini->prompt('Count to ten.')->stream(fn($chunk) => null));

$response->text();         // full accumulated text
$response->chunks();       // array<string> of individual chunks
$response->chunkCount();   // int
$response->events();       // array<SSEEvent>
$response->eventCount();   // int
$response->lastEventId();  // ?string
$response->status();       // int HTTP status
$response->successful();   // bool

// Format a chunk as an SSE string manually
$sse = $response->formatAsSSE('Hello', 'message');
// "event: message\ndata: {\"content\":\"Hello\"}\n\n"

// Format the entire accumulated text as a single SSE event
$sse = $response->formatCompleteAsSSE('complete');
```

### SSE reconnection

By default, the client reconnects automatically with exponential backoff (up to 10 attempts, 1–30s delay, jitter enabled). Override this per-prompt:

```php
use Hibla\HttpClient\SSE\SSEReconnectConfig;

$reconnect = new SSEReconnectConfig(
    enabled:           true,
    maxAttempts:       5,
    initialDelay:      0.5,
    maxDelay:          10.0,
    backoffMultiplier: 2.0,
    jitter:            true,
);

await(
    $gemini->prompt('Stream a long analysis.')
        ->stream(fn($chunk) => print($chunk), $reconnect)
);
```

Or set a default reconnect config on the client that all prompts inherit:

```php
$gemini = (new GeminiClient(apiKey: $key))
    ->withReconnectConfig($reconnect);
```

---

## Embeddings

### Single embedding

```php
$response = await($gemini->embed('The quick brown fox')->send());

$vector = $response->values();    // array<float>
$vector = $response->embeddings(); // alias for values()
$json   = $response->json();       // full decoded response
$raw    = $response->raw();        // underlying ResponseInterface
```

Pass an array of strings to embed multiple texts in a single request:

```php
$response = await(
    $gemini->embed(['Text one', 'Text two', 'Text three'])->send()
);

$vectors = $response->values(); // array<array<float>>
```

### Batch embeddings

`batchEmbed()` sends multiple independently configured embedding requests in a single API call. Each entry can have its own task type and title:

```php
$response = await(
    $gemini->batchEmbed()
        ->add('What is machine learning?', 'RETRIEVAL_QUERY')
        ->add('Introduction to neural networks', 'RETRIEVAL_DOCUMENT', 'Neural Networks')
        ->add('PHP is a scripting language', 'RETRIEVAL_DOCUMENT', 'PHP Overview')
        ->send()
);

$vectors = $response->values(); // array<array<float>>
```

### Output dimensionality

Reduce embedding vector size using Matryoshka Representation Learning. Supported by `gemini-embedding-001` and `text-embedding-004`. Recommended values are `3072` (full), `1536`, and `768`.

> **Important:** Only 3072-dimensional embeddings are pre-normalized by the API. If you use a smaller dimension, normalize the vectors before computing cosine similarity.

```php
$response = await(
    $gemini->embed('Search query text')
        ->outputDimensionality(768)
        ->send()
);
```

For batch embeddings, dimensionality applies to every item in the batch:

```php
$response = await(
    $gemini->batchEmbed()
        ->outputDimensionality(1536)
        ->add('Document one')
        ->add('Document two')
        ->send()
);
```

### Task types

The task type hint improves embedding quality for specific use cases. Set it via `taskType()` on single embeddings, or per-item in `batchEmbed()->add()`:

```php
$gemini->embed($query)->taskType('RETRIEVAL_QUERY')->send();
$gemini->embed($doc)->taskType('RETRIEVAL_DOCUMENT')->send();
$gemini->embed($text)->taskType('SEMANTIC_SIMILARITY')->send();
$gemini->embed($code)->taskType('CODE_RETRIEVAL_QUERY')->send();
```

| Task type | Use when |
|-----------|----------|
| `RETRIEVAL_QUERY` | The text is a search query |
| `RETRIEVAL_DOCUMENT` | The text is a document to be retrieved |
| `SEMANTIC_SIMILARITY` | Comparing semantic similarity between texts |
| `CLASSIFICATION` | Text classification tasks |
| `CLUSTERING` | Grouping texts by topic |
| `CODE_RETRIEVAL_QUERY` | Searching a code base |

For `RETRIEVAL_DOCUMENT`, you can also supply a title:

```php
$response = await(
    $gemini->embed('PHP was created by Rasmus Lerdorf in 1994.')
        ->taskType('RETRIEVAL_DOCUMENT')
        ->title('History of PHP')
        ->send()
);
```

---

## Semantic search

`search()` embeds the query and all documents, computes cosine similarity between them, and returns the results sorted by relevance. Everything — the query embedding, all document embeddings, and the ranking — runs in a single `await()` call.

### Basic search

```php
$results = await(
    $gemini->search('What is async programming?')
        ->documents([
            'Async programming allows non-blocking execution of code.',
            'PHP is a popular server-side scripting language.',
            'Event loops process async tasks without blocking threads.',
            'Composer is the dependency manager for PHP.',
        ])
        ->send()
);

foreach ($results as $result) {
    printf("[%.4f] %s\n", $result['similarity'], $result['text']);
}
```

Each result in the returned array has the shape:

```php
[
    'text'       => string,  // original document text
    'similarity' => float,   // cosine similarity score (-1.0 to 1.0)
    'index'      => int,     // original position in the documents array
]
```

Results are sorted descending by `similarity`, so `$results[0]` is always the best match.

### Custom model and dimensionality

```php
$results = await(
    $gemini->search('neural network architecture')
        ->model('text-embedding-004')
        ->outputDimensionality(1536)
        ->documents($corpus)
        ->send()
);
```

> The query and all document embeddings use the same model and dimensionality. Mixing them will throw an `InvalidArgumentException` during cosine similarity calculation.

---

## Transport configuration

### Retry configuration

The default retry policy makes 3 attempts with a 2s base delay and 2× exponential backoff. Override it with a `RetryConfig`:

```php
use Hibla\HttpClient\ValueObjects\RetryConfig;

$gemini = (new GeminiClient(apiKey: $key))
    ->withRetryConfig(new RetryConfig(
        maxRetries:           5,
        baseDelay:            1.0,
        maxDelay:             30.0,
        backoffMultiplier:    2.0,
        jitter:               true,
        retryableStatusCodes: [429, 500, 502, 503, 504],
    ));
```

Retry only applies to non-streaming requests (`send()`). SSE connections use the separate reconnection config described in [SSE reconnection](#sse-reconnection).

### Custom HTTP client

Inject any `HttpClientInterface` implementation — useful for testing or swapping transports:

```php
use Hibla\HttpClient\Http;

$httpClient = Http::client()
    ->timeout(120)
    ->verifySSL(true)
    ->withProxy('proxy.internal', 8080);

$gemini = new GeminiClient(apiKey: $key, httpClient: $httpClient);
```

When a custom client is provided, `GeminiClient` inherits its transport settings (timeouts, proxy, SSL) and layers its own concerns (API key header, retry, JSON encoding) on top.

### Default headers

Merge custom headers into every HTTP request made by the client:

```php
$gemini = (new GeminiClient(apiKey: $key))
    ->withHeaders([
        'X-Request-Source' => 'my-app',
        'X-Tenant-Id'      => $tenantId,
    ]);
```

`withHeaders()` returns a new clone and merges with any previously set headers, so multiple calls accumulate rather than replace.

---

## Model management

### Listing models

```php
use function Hibla\await;

$response = await($gemini->listModels());
$models   = $response->json(); // full decoded response
```

### Getting model info

```php
$response = await($gemini->getModel('gemini-2.0-flash'));
$info     = $response->json();

echo $info['displayName'];     // "Gemini 2.0 Flash"
echo $info['inputTokenLimit']; // 1048576
```

---

## API Reference

### `GeminiClientInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `prompt(string\|array $prompt)` | `GeminiPromptInterface` | Start a content generation builder |
| `embed(string\|array $content)` | `GeminiEmbeddingInterface` | Start a single or multi-text embedding builder |
| `batchEmbed()` | `GeminiBatchEmbeddingInterface` | Start a batch embedding builder |
| `search(string $query)` | `GeminiSearchInterface` | Start a semantic search builder |
| `listModels()` | `PromiseInterface<ResponseInterface>` | List all available models |
| `getModel(string $model)` | `PromiseInterface<ResponseInterface>` | Get info about a specific model |
| `withModel(string $model)` | `static` | Set the default generation model |
| `withEmbeddingModel(string $model)` | `static` | Set the default embedding model |
| `withRetryConfig(RetryConfig $config)` | `static` | Override retry behaviour |
| `withReconnectConfig(SSEReconnectConfig $config)` | `static` | Override SSE reconnection defaults |
| `withHeaders(array $headers)` | `static` | Merge default headers into all requests |

### `GeminiPromptInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `model(string $model)` | `static` | Override the model for this prompt |
| `system(string\|array $instruction)` | `static` | Set the system instruction |
| `tools(array $tools)` | `static` | Set function-calling tools |
| `temperature(float $temperature)` | `static` | Set generation temperature (0.0 – 2.0) |
| `maxTokens(int $maxTokens)` | `static` | Set max output tokens |
| `topP(float $topP)` | `static` | Set nucleus sampling threshold |
| `topK(int $topK)` | `static` | Set top-K sampling |
| `creative()` | `static` | Preset: high temperature / topP |
| `balanced()` | `static` | Preset: moderate temperature / topP |
| `precise()` | `static` | Preset: low temperature / topP |
| `code()` | `static` | Preset: low temperature, moderate topK |
| `send()` | `PromiseInterface<GeminiResponseInterface>` | Execute and return complete response |
| `stream(callable $onChunk, ?SSEReconnectConfig $config)` | `PromiseInterface<GeminiStreamResponseInterface>` | Execute with raw chunk callback |
| `streamSSE(array $config, ?SSEReconnectConfig $config)` | `PromiseInterface<GeminiStreamResponseInterface>` | Execute and emit browser SSE |
| `streamWithEvents(string $messageEvent, ?string $doneEvent, bool $includeMetadata)` | `PromiseInterface<GeminiStreamResponseInterface>` | Simplified SSE with event name config |

### `GeminiResponseInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `text()` | `string` | Extracted generated text |
| `json(?string $key, mixed $default)` | `mixed` | Full JSON or dot-notation key access |
| `status()` | `int` | HTTP status code |
| `headers()` | `array` | Response headers |
| `successful()` | `bool` | True for 2xx status |
| `candidate()` | `array\|null` | First candidate object |
| `candidates()` | `array` | All candidates |
| `usage()` | `array\|null` | `usageMetadata` (token counts) |
| `modelVersion()` | `string\|null` | Specific model version used |
| `raw()` | `ResponseInterface` | Underlying HTTP response |

### `GeminiStreamResponseInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `text()` | `string` | Complete accumulated text |
| `chunks()` | `array<string>` | All individual chunks received |
| `chunkCount()` | `int` | Number of chunks received |
| `events()` | `array<SSEEvent>` | All raw SSE events received |
| `eventCount()` | `int` | Number of events received |
| `lastEventId()` | `string\|null` | Last SSE event ID |
| `status()` | `int` | HTTP status code |
| `headers()` | `array` | Response headers |
| `successful()` | `bool` | True for 2xx status |
| `formatAsSSE(string $chunk, ?string $eventType)` | `string` | Format a chunk as an SSE string |
| `formatCompleteAsSSE(?string $eventType)` | `string` | Format the full text as an SSE string |
| `raw()` | `SSEResponseInterface` | Underlying SSE response |

### `GeminiEmbeddingInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `model(string $model)` | `static` | Override embedding model |
| `taskType(string $taskType)` | `static` | Set task type hint |
| `title(string $title)` | `static` | Set document title (RETRIEVAL_DOCUMENT only) |
| `outputDimensionality(int $dimensions)` | `static` | Reduce output vector size |
| `send()` | `PromiseInterface<GeminiEmbeddingResponseInterface>` | Execute the embedding request |

### `GeminiBatchEmbeddingInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `model(string $model)` | `static` | Override embedding model for the batch |
| `outputDimensionality(int $dimensions)` | `static` | Reduce output vector size for all items |
| `add(string $content, string $taskType, ?string $title)` | `static` | Add an item to the batch |
| `send()` | `PromiseInterface<GeminiEmbeddingResponseInterface>` | Execute the batch request |

### `GeminiSearchInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `documents(array $documents)` | `static` | Set the document corpus to search |
| `model(string $model)` | `static` | Override the embedding model |
| `outputDimensionality(int $dimensions)` | `static` | Set vector size for query and documents |
| `send()` | `PromiseInterface<array>` | Execute the search and return ranked results |

### `GeminiEmbeddingResponseInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `values()` | `array<float>\|array<array<float>>` | Embedding vector(s) |
| `embeddings()` | `array<float>\|array<array<float>>` | Alias for `values()` |
| `json()` | `mixed` | Full decoded response |
| `raw()` | `ResponseInterface` | Underlying HTTP response |

---

## Exceptions

| Exception | When thrown |
|-----------|-------------|
| `\RuntimeException` | API-level errors in the response body (surfaced by `text()` and `json()`), invalid response format, missing embeddings, or calling `batchEmbed()->send()` with no items added |
| `\InvalidArgumentException` | Mismatched vector lengths during cosine similarity (e.g. mixing embedding dimensionalities in `search()`) |
| `NetworkException` | Transport-level failure: DNS, SSL, connection refused |
| `TimeoutException` | Request or connection timeout exceeded |

---

## License

This project is not affiliated with the Gemini AI platform.

MIT License. see [LICENSE.md](./LICENSE.md) for more information
