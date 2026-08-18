<?php

declare(strict_types=1);

namespace OrderApi\Domain\Order;

/**
 * Only the states this application can actually be in.
 *
 * No "processing", "paid" or "shipped": there is no payment and no fulfilment
 * here, and inventing states for a workflow that does not exist would mean
 * writing transitions nothing can ever trigger.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
