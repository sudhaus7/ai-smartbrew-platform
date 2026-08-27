<?php
declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Smartbrew;

use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\FinishReason\FinishReasonAwareTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\Vector\Vector;

class SmartbrewResultConverter implements ResultConverterInterface{
    use FinishReasonAwareTrait;

    public function supports( Model $model ): bool {
        return $model instanceof Smartbrew;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface {
        return new TokenUsageExtractor();
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        return \array_key_exists('embeddings', $data)
            ? $this->doConvertEmbeddings($data)
            : $this->doConvertCompletion($data);
    }

    private function doConvertCompletion(array $data): ResultInterface
    {
        if (!isset($data['choices'][0]['message'])) {
            throw new RuntimeException('Response does not contain message.');
        }

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new RuntimeException('Message does not contain content.');
        }

        $toolCalls = [];

        foreach ($data['choices'][0]['message']['tool_calls'] ?? [] as $id => $toolCall) {
            $toolCalls[] = new ToolCall($id, $toolCall['function']['name'], $toolCall['function']['arguments']);
        }

        if ([] !== $toolCalls) {
            return $this->withFinishReason(new ToolCallResult($toolCalls), FinishReasonMapper::map($data['choices'][0]['finish_reason'] ?? null));
        }


        if (isset($data['choices'][0]['message']['reasoning_content'])) {
            $myResult = new MultiPartResult([
                new TextResult($data['choices'][0]['message']['content']),
                new ThinkingResult($data['choices'][0]['message']['reasoning_content']),
            ]);
        } else {
            $myResult = new TextResult($data['choices'][0]['message']['content']);
        }

        return $this->withFinishReason($myResult, FinishReasonMapper::map($data['choices'][0]['finish_reason'] ?? null));
    }
    private function doConvertEmbeddings(array $data): ResultInterface
    {
        if ([] === $data['embeddings']) {
            throw new RuntimeException('Response does not contain embeddings.');
        }

        return new VectorResult(
            array_map(
                static fn (array $embedding): Vector => new Vector($embedding),
                $data['embeddings'],
            ),
        );
    }

    private function convertStream(RawResultInterface $result): \Generator
    {
        $toolCalls = [];
        $sawChunk = false;
        $sawDone = false;
        $finishReason = null;
        foreach ($result->getDataStream() as $data) {
            // Smartbrew emits {"error": "..."} on HTTP 200 in practice; not part of the
            // documented schema, so this guard is defensive.
            if (isset($data['error'])) {
                throw new RuntimeException(\sprintf('Smartbrew stream error: "%s".', \is_string($data['error']) ? $data['error'] : 'Unknown error'));
            }

            $sawChunk = true;

            if (isset($data['done']) && true === $data['done']) {
                $sawDone = true;

                if (null !== ($data['done_reason'] ?? null)) {
                    $finishReason = FinishReasonMapper::map($data['done_reason']);
                }
            }

            if ($this->streamIsToolCall($data)) {
                $toolCalls = $this->convertStreamToToolCalls($toolCalls, $data);
            }

            if ($this->hasThinkingDelta($data)) {
                yield new ThinkingDelta($data['message']['thinking']);
            }

            if ($this->hasTextDelta($data)) {
                yield new TextDelta($data['message']['content']);
            }

            if ([] !== $toolCalls && $this->isToolCallsStreamFinished($data)) {
                yield new ToolCallComplete($toolCalls);
            }

            if ($this->hasStreamTokenUsage($data)) {
                yield new TokenUsage(
                    promptTokens: $data['prompt_eval_count'],
                    completionTokens: $data['eval_count'],
                );
            }
        }

        if ($sawChunk && !$sawDone) {
            throw new IncompleteStreamException('Smartbrew stream ended before a "done" message.');
        }

        if (null !== $finishReason) {
            yield new MetadataDelta('finish_reason', $finishReason);
        }
    }

    private function convertStreamToToolCalls(array $toolCalls, array $data): array
    {
        if (!isset($data['message']['tool_calls'])) {
            return $toolCalls;
        }

        foreach ($data['message']['tool_calls'] ?? [] as $id => $toolCall) {
            $toolCalls[] = new ToolCall($id, $toolCall['function']['name'], $toolCall['function']['arguments']);
        }

        return $toolCalls;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function streamIsToolCall(array $data): bool
    {
        return isset($data['message']['tool_calls']);
    }

    /**
     * @param array<string, mixed> $data^
     */
    private function isToolCallsStreamFinished(array $data): bool
    {
        return isset($data['done']) && true === $data['done'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasStreamTokenUsage(array $data): bool
    {
        return isset($data['prompt_eval_count'], $data['eval_count']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasTextDelta(array $data): bool
    {
        return isset($data['message']['content']) && '' !== $data['message']['content'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasThinkingDelta(array $data): bool
    {
        return isset($data['message']['thinking']) && '' !== $data['message']['thinking'];
    }
}
