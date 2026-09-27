<?php

namespace App\Services\Chatbot;

use App\Ai\Agents\ChatbotResponseAgent;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class ChatbotAiAdapter
{
    /**
     * Generate chatbot wording from application-prepared input.
     *
     * @param  array<string|int, mixed>  $context
     * @return array{successful: true, text: string, failure: null}|array{successful: false, text: null, failure: string}
     */
    public function generate(string $prompt, array $context): array
    {
        $preparedPrompt = $this->preparePrompt($prompt, $context);
        $agent = new ChatbotResponseAgent;
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
