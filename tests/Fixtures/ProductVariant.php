<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Fixtures;

use Sylius\Component\Core\Model\ProductVariant as BaseProductVariant;

class ProductVariant extends BaseProductVariant
{
    public ?string $ean13 = null;

    public function getEan13(): ?string
    {
        return $this->ean13;
    }
}
