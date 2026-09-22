<?php

namespace App\Services\Chatbot;

use App\Ai\Agents\ChatbotResponseAgent;
use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

class ChatbotAiAdapter
{
    /**
     * Generate chatbot wording from application-prepared input.
     *
     * @param  array<string|int, mixed>  $context
     * @return array{successful: true, text: string, failure: null}|array{successful: false, text: null, failure: 'timeout'|'provider_unavailable'}
     */
    public function generate(string $prompt, array $context): array
    {
        $preparedPrompt = $this->preparePrompt($prompt, $context);

        try {
            $text = trim((new ChatbotResponseAgent)->prompt($preparedPrompt)->text);
        } catch (ProviderConnectionException) {
            return [
                'successful' => false,
                'text' => null,
                'failure' => 'timeout',
            ];
        } catch (Throwable) {
            return [
                'successful' => false,
                'text' => null,
                'failure' => 'provider_unavailable',
            ];
        }

        if ($text === '') {
            return [
                'successful' => false,
                'text' => null,
                'failure' => 'provider_unavailable',
            ];
        }

        return [
            'successful' => true,
            'text' => $text,
            'failure' => null,
        ];
    }

    /**
     * @param  array<string|int, mixed>  $context
     */
    private function preparePrompt(string $prompt, array $context): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new InvalidArgumentException('The prepared chatbot prompt must not be blank.');
        }

        $this->ensureScalarContext($context);

        try {
            $encodedPrompt = json_encode(
                $prompt,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $encodedContext = json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The prepared chatbot input must be valid UTF-8 JSON data.',
                previous: $exception,
            );
        }

        return <<<PROMPT
            Customer message (JSON string):
            {$encodedPrompt}

            Authoritative context (JSON):
            {$encodedContext}
            PROMPT;
    }

    /**
     * @param  array<string|int, mixed>  $context
     */
    private function ensureScalarContext(array $context): void
    {
        foreach ($context as $value) {
            if (is_array($value)) {
                $this->ensureScalarContext($value);

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(
                    'The prepared chatbot context may contain only arrays, scalar values, and null.',
                );
            }
        }
    }
}
