<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Sylius\Component\Core\Model\OrderInterface;

interface PurchaseEventBuilderInterface
{
    /**
     * @return array<string, mixed> one purchase event, as sent in the bulk_sync_insert array
     */
    public function build(OrderInterface $order): array;
}
