<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Public addresses of a product page and of its main image, sent with each product of a
 * purchase event. Replace the default service (alias of this interface) when the shop builds
 * its URLs its own way.
 */
interface ProductLinkResolverInterface
{
    public function productUrl(ProductInterface $product, OrderInterface $order): ?string;

    public function imageUrl(ProductInterface $product, OrderInterface $order): ?string;
}
