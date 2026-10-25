<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Unit;

use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\ProductLinkResolverInterface;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventBuilder;
use Altmenorg\SyliusSkeepersPlugin\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;

final class PurchaseEventBuilderTest extends TestCase
{
    private function links(): ProductLinkResolverInterface
    {
        return new class() implements ProductLinkResolverInterface {
            public function productUrl(ProductInterface $product, OrderInterface $order): ?string
            {
                return 'https://shop.example.com/' . $product->getSlug() . '.html';
            }

            public function imageUrl(ProductInterface $product, OrderInterface $order): ?string
            {
                return $product->getCode() === '1C1B' ? 'https://cdn.example.com/1c1b.jpg' : null;
            }
        };
    }

    private function builder(): PurchaseEventBuilder
    {
        return new PurchaseEventBuilder(
            $this->links(),
            'd0accd7e-32c8-11ee-be56-0242ac120002',
            excludedProductCodes: ['ZZ9Z'],
            excludedItemFlag: 'giftWrap',
            brandPath: 'brand.name',
            defaultBrand: 'House brand',
            eanPath: 'ean13',
        );
    }

    public function testOrderGivesTheDocumentedBody(): void
    {
        $order = Fixtures::order();
        $curcuma = Fixtures::product('1C1B', 'Curcuma', 'Mes Épices');
        $pepper = Fixtures::product('2A2A', 'Poivre noir');
        $wrap = Fixtures::product('ZZ1A', 'Sac cadeau');
        $service = Fixtures::product('ZZ9Z', 'Service');

        Fixtures::item($order, Fixtures::variant($curcuma, '1C1B-1', '3700000000017'), 2, 450);
        Fixtures::item($order, Fixtures::variant($curcuma, '1C1B-2', '3700000000024'), 1, 990);
        Fixtures::item($order, Fixtures::variant($pepper, '2A2A-1', '3700000000031'), 1, 1290);
        Fixtures::item($order, Fixtures::variant($wrap, 'ZZ1A'), 1, 260, giftWrap: true);
        Fixtures::item($order, Fixtures::variant($service, 'ZZ9Z'), 1, 300);

        self::assertSame(3740, $order->getTotal());
        self::assertSame([
            'purchase_reference' => '230003',
            'purchase_date' => '2026-10-23T10:51:45Z',
            'price' => 37.4,
            'consumer' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane.doe@example.com',
                'phone' => '0600000000',
                'language' => 'fr',
                'country' => 'FR',
            ],
            'products' => [
                [
                    // two variants with two EANs: one product, no EAN, price of the first line
                    'product_ref' => ['reference' => '1C1B'],
                    'name' => 'Curcuma',
                    'brand' => 'Mes Épices',
                    'price' => 4.5,
                    'product_url' => 'https://shop.example.com/1c1b.html',
                    'image_url' => 'https://cdn.example.com/1c1b.jpg',
                ],
                [
                    'product_ref' => ['reference' => '2A2A', 'ean' => '3700000000031'],
                    'name' => 'Poivre noir',
                    'brand' => 'House brand',
                    'price' => 12.9,
                    'product_url' => 'https://shop.example.com/2a2a.html',
                ],
            ],
            'sales_channel' => ['channel' => 'online', 'website_id' => 'd0accd7e-32c8-11ee-be56-0242ac120002'],
        ], $this->builder()->build($order));
    }

    public function testJsonHasNoSolicitationParametersAndNoNulls(): void
    {
        $order = Fixtures::order('100');
        $order->setBillingAddress(null);
        $order->getCustomer()?->setPhoneNumber(null);
        Fixtures::item($order, Fixtures::variant(Fixtures::product('A1', 'Thing'), 'A1'), 1, 1000);

        $event = (new PurchaseEventBuilder($this->links(), 'w'))->build($order);
        $json = json_encode([$event], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        self::assertArrayNotHasKey('solicitation_parameters', $event);
        self::assertStringNotContainsString('null', (string) $json);
        // no billing address: names of the customer, no phone, no country
        self::assertSame(['first_name' => 'Customer first', 'last_name' => 'Customer last', 'email' => 'jane.doe@example.com', 'language' => 'fr'], $event['consumer']);
        self::assertSame([['product_ref' => ['reference' => 'A1'], 'name' => 'Thing', 'price' => 10.0, 'product_url' => 'https://shop.example.com/a1.html']], $event['products']);
    }

    public function testOrderWithOnlyServiceLinesHasNoProducts(): void
    {
        $order = Fixtures::order();
        Fixtures::item($order, Fixtures::variant(Fixtures::product('ZZ1A', 'Sac cadeau'), 'ZZ1A'), 1, 260, giftWrap: true);

        self::assertArrayNotHasKey('products', $this->builder()->build($order));
    }
}
