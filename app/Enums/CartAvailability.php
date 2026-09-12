<?php

namespace App\Enums;

enum CartAvailability: string
{
    case Available = 'available';
    case ProductIneligible = 'product_ineligible';
    case InventoryUnavailable = 'inventory_unavailable';
    case OutOfStock = 'out_of_stock';
    case InsufficientStock = 'insufficient_stock';
}
