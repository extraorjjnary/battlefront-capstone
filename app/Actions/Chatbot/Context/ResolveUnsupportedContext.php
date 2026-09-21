<?php

namespace App\Actions\Chatbot\Context;

class ResolveUnsupportedContext
{
    /**
     * Return the explicit empty context for unsupported inquiries.
     *
     * @return array<never, never>
     */
    public function execute(): array
    {
        return [];
    }
}
