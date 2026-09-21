<?php

namespace App\Enums;

enum ChatbotCategory: string
{
    case Product = 'product';
    case Order = 'order';
    case Store = 'store';
    case Faq = 'faq';

    /**
     * Get the administrator-facing category label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Order => 'Order',
            self::Store => 'Store',
            self::Faq => 'FAQ',
        };
    }
}
