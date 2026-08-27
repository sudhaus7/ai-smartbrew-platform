Smartbrew Platform
==================

[smartbrew.ai](https://www.smartbrew.ai/) platform bridge for Symfony AI.

Smartbrew exposes an OpenAI-compatible chat API in front of an Ollama backend, so this bridge
combines an OpenAI-shaped request/response contract with Ollama-style option handling and NDJSON
streaming.

This bridge includes support for:

- Chat completions (`POST api/chat/completions`)
- Embeddings (`POST api/embeddings`)
- Streaming responses (NDJSON deltas)
- Reasoning / thinking output
- Tool calling
- Structured output (JSON schema)
- Dynamic model discovery (`GET api/models`)

Installation
------------

```bash
composer require smartbrew/ai-smartbrew-platform
```

Requires PHP 8.2+, `symfony/ai-platform` ^0.12 and `symfony/http-client` ^7.3|^8.0.

Usage
-----

```php
use Symfony\AI\Platform\Bridge\Smartbrew\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$platform = Factory::createPlatform(
    apiKey: $_ENV['SMARTBREW_API_KEY'],     // sent as `Authorization: Bearer …`
);

$result = $platform->invoke('openai/gpt-oss', new MessageBag(
    Message::forSystem('You are a helpful assistant.'),
    Message::ofUser('What is Symfony AI?'),
));

echo $result->asText();
```

`Factory::createProvider()` returns the bare provider if you want to compose it with other providers
in your own `Platform` instance. Both factory methods accept a custom `HttpClientInterface`,
`Contract` and `EventDispatcherInterface`.

### Streaming

Streaming is opt-in — the bridge sends `stream: false` unless you ask for it:

```php
$result = $platform->invoke('openai/gpt-oss', $messages, ['stream' => true]);

foreach ($result->asStream() as $delta) {
    echo $delta;
}
```

Text, thinking and completed tool calls are emitted as separate deltas, followed by token usage and
a `finish_reason` metadata delta.

### Options

Option handling follows Ollama's split between top-level request keys and nested model options.
Known top-level keys (`stream`, `format`, `keep_alive`, `tools`, `think`, `logprobs`,
`top_logprobs` for chat; `truncate`, `keep_alive`, `dimensions` for embeddings) stay at the root of
the payload, everything else is moved into `options`:

```php
$platform->invoke('openai/gpt-oss', $messages, [
    'think' => true,       // top level
    'temperature' => 0.2,  // → options.temperature
]);
```

Models
------

The model catalog is resolved at runtime from `GET api/models` — there is no hardcoded model list.
Capabilities are derived from the server's model metadata:

| Smartbrew / Ollama capability | Symfony AI capability |
|-------------------------------|-----------------------|
| `completion`                  | `INPUT_MESSAGES`      |
| `embedding`                   | `EMBEDDINGS`          |
| `tools`, `builtin_tools`      | `TOOL_CALLING`        |
| `thinking`                    | `THINKING`            |
| `vision`                      | `INPUT_IMAGE`         |

Every non-embedding model additionally gets `OUTPUT_STRUCTURED`. Presets (models flagged
`preset: true`) are read from `info.meta.capabilities`, plain models from `ollama.capabilities`.
A model whose metadata carries no capabilities raises an `InvalidArgumentException` — that usually
means the Smartbrew server is too old and needs an upgrade.

Resources
---------

 * [Smartbrew](https://www.smartbrew.ai/)

 * [Symfony AI documentation](https://symfony.com/doc/current/ai/index.html)
 * [Symfony AI repository](https://github.com/symfony/ai)

License
-------

MIT
