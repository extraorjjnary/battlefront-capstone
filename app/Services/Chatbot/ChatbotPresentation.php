<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotPresentation
{
    public const INVENTORY_NOTICE = 'Demo inventory: Stock quantities are simulated.';

    public static function productName(string $name): string
    {
        return preg_replace('/^\[DEMO\]\s*/iu', '', $name) ?? $name;
    }

    public function isStockQuestion(string $message): bool
    {
        return preg_match('/\b(?:stock|stocks|available|availability|have|sell)\b/iu', $message) === 1;
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context, string $message): array
    {
        foreach ($context['products'] ?? [] as $index => $product) {
            $context['products'][$index]['name'] = self::productName($product['name']);
            if (isset($product['inventory_notice'])) {
                unset($context['products'][$index]['inventory_notice']);
                if ($this->isStockQuestion($message)) {
                    $context['products'][$index]['inventory_notice'] = self::INVENTORY_NOTICE;
                }
            }
        }

        return $context;
    }

    public function generated(string $text, bool $simulatedStock = false): string
    {
        $text = str_replace((string) config('catalog-demo.notice'), '', $text);
        $text = preg_replace('/\[DEMO\]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/[`"\']?\b(?:is_demo|is_demonstration|delivery_is_demo)\b[`"\']?\s*[:=]\s*(?:true|false)\b[`,;]?/iu', '', $text) ?? $text;
        $text = trim($text);
        if ($simulatedStock && ! Str::contains(Str::lower($text), ['simulated stock', 'stock quantities are simulated'])) {
            $text .= ' Stock quantities are simulated.';
        }

        return $text;
    }
}
