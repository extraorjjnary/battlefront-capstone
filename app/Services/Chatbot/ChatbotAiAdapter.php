<?php

namespace App\Services\Chatbot;

use App\Ai\Agents\ChatbotIntentAgent;
use App\Ai\Agents\ChatbotResponseAgent;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class ChatbotAiAdapter
{
    /** @return array{outcome: string, category: string, intent: string, language: string}|null */
    public function classify(string $message): ?array
    {
        $agent = new ChatbotIntentAgent;
        $started = hrtime(true);
        try {
            $message = preg_replace('/\bbf[\s\W_]*\d+\b/iu', '[order reference]', $message) ?? $message;
            $response = $agent->prompt($this->preparePrompt($message, ['allowed_intents' => ChatbotIntentAgent::INTENTS]));
            if (! $response instanceof StructuredAgentResponse) {
                $this->logAttempt($started, $agent->timeout(), 'invalid_classification');

                return null;
            }
            $data = $response->structured;
            $validator = Validator::make(['classification' => $data], [
                'classification' => ['required', 'array:outcome,category,intent,language'],
                'classification.outcome' => ['required', Rule::in(['supported', 'uncertain', 'unsupported'])],
                'classification.category' => ['required', Rule::in(['product', 'store', 'order', 'faq', 'unsupported'])],
                'classification.intent' => ['required', Rule::in([...array_keys(ChatbotIntentAgent::INTENTS), 'none'])],
                'classification.language' => ['required', Rule::in([...ChatbotLanguage::LANGUAGES, 'und'])],
            ]);
            if ($validator->fails()
                || ! is_string($data['outcome']) || ! is_string($data['category'])
                || ! is_string($data['intent']) || ! is_string($data['language'])
                || ($data['outcome'] === 'supported'
                    ? (ChatbotIntentAgent::INTENTS[$data['intent']]['category'] ?? null) !== $data['category']
                    : ($data['category'] !== 'unsupported' || $data['intent'] !== 'none'))) {
                $this->logAttempt($started, $agent->timeout(), 'invalid_classification');

                return null;
            }
            $this->logAttempt($started, $agent->timeout());

            return ['outcome' => $data['outcome'], 'category' => $data['category'], 'intent' => $data['intent'], 'language' => $data['language']];
        } catch (Throwable $exception) {
            $details = $this->failureDetails($exception);
            $this->logAttempt($started, $agent->timeout(), $details['failure'], $details['status'], $exception::class);

            return null;
        }
    }

    /**
     * Generate chatbot wording from application-prepared input.
     *
     * @param  array<string|int, mixed>  $context
     * @return array{successful: true, text: string, failure: null}|array{successful: false, text: null, failure: string}
     */
    public function generate(string $prompt, array $context, string $language = 'en'): array
    {
        $preparedPrompt = $this->preparePrompt($prompt, $context);
        $agent = new ChatbotResponseAgent($language);
        $started = hrtime(true);

        try {
            $text = trim($agent->prompt($preparedPrompt)->text);
        } catch (Throwable $exception) {
            $details = $this->failureDetails($exception);
            $this->logAttempt($started, $agent->timeout(), $details['failure'], $details['status'], $exception::class);

            return [
                'successful' => false,
                'text' => null,
                'failure' => $details['failure'],
            ];
        }

        if ($text === '') {
            $this->logAttempt($started, $agent->timeout(), 'empty_response');

            return [
                'successful' => false,
                'text' => null,
                'failure' => 'empty_response',
            ];
        }

        $this->logAttempt($started, $agent->timeout());

        return [
            'successful' => true,
            'text' => $text,
            'failure' => null,
        ];
    }

    /** @return array{failure: string, status: int|null} */
    private function failureDetails(Throwable $exception): array
    {
        $status = null;
        $timeout = false;
        $connection = false;

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException) {
                $status = $cause->response->status();
            }
            $timeout = $timeout || $cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException;
            $connection = $connection || $cause instanceof ProviderConnectionException || $cause instanceof ConnectionException || $cause instanceof NetworkException;
        }

        return [
            'failure' => match (true) {
                $timeout, in_array($status, [408, 504], true) => 'timeout',
                $exception instanceof RateLimitedException, $status === 429 => 'rate_limited',
                $exception instanceof InsufficientCreditsException, $status === 402 => 'quota_exhausted',
                in_array($status, [401, 403], true) => 'authentication_failed',
                $connection => 'connection_failed',
                $exception instanceof ProviderOverloadedException => 'provider_overloaded',
                default => 'provider_unavailable',
            },
            'status' => $status,
        ];
    }

    private function logAttempt(int $started, int $timeout, ?string $failure = null, ?int $status = null, ?string $exceptionClass = null): void
    {
        $context = [
            'provider' => 'gemini',
            'model' => 'gemini-3.5-flash-lite',
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'timeout_seconds' => $timeout,
            'failure' => $failure,
            'http_status' => $status,
            'exception_class' => $exceptionClass,
        ];

        if ($failure === null) {
            Log::info('Chatbot provider attempt completed.', $context);
        } else {
            Log::warning('Chatbot provider attempt failed.', $context);
        }
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
