<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case CardAtStore = 'card_at_store';
    case GCash = 'gcash';
    case Maya = 'maya';
}
