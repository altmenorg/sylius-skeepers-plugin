<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Fixtures;

use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Product as BaseProduct;

/** Builds real Sylius orders for the tests. */
final class Fixtures
{
    public static function product(string $code, string $name, ?string $brand = null): Product
    {
        $product = new Product();
        $product->setCurrentLocale('fr_FR');
        $product->setFallbackLocale('fr_FR');
        $product->setCode($code);
        $product->setName($name);
        $product->setSlug(strtolower($code));
        $product->brand = $brand !== null ? new Brand($brand) : null;

        return $product;
    }

    public static function variant(BaseProduct $product, string $code, ?string $ean = null): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setCode($code);
        $variant->ean13 = $ean;
        $product->addVariant($variant);

        return $variant;
    }

    /** Line of $quantity units at $unitPrice cents. */
    public static function item(Order $order, ProductVariant $variant, int $quantity, int $unitPrice, bool $giftWrap = false): OrderItem
    {
        $item = new OrderItem();
        $item->setVariant($variant);
        $item->setProductName($variant->getProduct()?->getName());
        $item->setUnitPrice($unitPrice);
        $item->giftWrap = $giftWrap;
        $order->addItem($item);
        for ($i = 0; $i < $quantity; ++$i) {
            new OrderItemUnit($item);
        }

        return $item;
    }

    public static function order(string $number = '230003'): Order
    {
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setHostname('shop.example.com');

        $customer = new Customer();
        $customer->setEmail('jane.doe@example.com');
        $customer->setFirstName('Customer first');
        $customer->setLastName('Customer last');
        $customer->setPhoneNumber('+33100000000');

        $billing = new Address();
        $billing->setFirstName('Jane');
        $billing->setLastName('Doe');
        $billing->setPhoneNumber('0600000000');
        $billing->setCountryCode('fr');

        $order = new Order();
        $order->setNumber($number);
        $order->setChannel($channel);
        $order->setCustomer($customer);
        $order->setBillingAddress($billing);
        $order->setLocaleCode('fr_FR');
        $order->setCurrencyCode('EUR');
        $order->setCheckoutCompletedAt(new \DateTimeImmutable('2026-10-23 12:51:45', new \DateTimeZone('Europe/Paris')));

        return $order;
    }
}
