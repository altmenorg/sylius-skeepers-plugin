<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Review;

/**
 * Finds the Sylius product (ID) a published Skeepers review is about. Replace the default
 * service (alias of this interface), or decorate it, when the shop needs other rules.
 */
interface ReviewProductResolverInterface
{
    /**
     * @param array<string, mixed> $review one review as the verified-reviews API gives it
     */
    public function resolve(array $review): ?int;
}
