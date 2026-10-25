<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Fixtures;

use Sylius\Component\Core\Model\OrderItem as BaseOrderItem;

class OrderItem extends BaseOrderItem
{
    public bool $giftWrap = false;

    public function isGiftWrap(): bool
    {
        return $this->giftWrap;
    }
}
