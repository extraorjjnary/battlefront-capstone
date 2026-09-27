<?php

namespace App\Services\Chatbot;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class ChatbotConversationToken
{
    /** @param array<string, mixed> $state */
    public function encode(array $state, string $subject): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'subject' => hash('sha256', $subject),
            'expires_at' => now()->addMinutes(15)->timestamp,
            'state' => $state,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function decode(?string $token, string $subject): array
    {
        if ($token === null) {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [];
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['subject'] ?? null)
            || ! hash_equals(hash('sha256', $subject), $payload['subject'])
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] <= now()->timestamp
            || ! is_array($payload['state'] ?? null)) {
            return [];
        }

        return $payload['state'];
    }
}
