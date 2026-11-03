<?php

namespace App\Enums;

/** One course inside an order (order_items). */
enum OrderItemStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
