<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Fixtures;

use Sylius\Component\Core\Model\Product as BaseProduct;

class Product extends BaseProduct
{
    public ?Brand $brand = null;

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }
}
