<?php

namespace App\Enums;

enum ChatbotQueryCategory: string
{
    case Product = 'product';
    case Order = 'order';
    case Store = 'store';
    case Faq = 'faq';
    case Unsupported = 'unsupported';
}
