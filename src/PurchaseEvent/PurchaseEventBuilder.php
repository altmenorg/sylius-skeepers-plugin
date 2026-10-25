<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Builds the purchase event of an order, as the purchase-event API takes it
 * (https://apidocs.skeepers.io/purchase-events.html).
 *
 * - `purchase_reference`: the order number the customer sees;
 * - `purchase_date`: checkout completion, ISO 8601 in UTC;
 * - `price`: order total, in the order currency;
 * - `consumer`: billing name (customer name if missing), email, phone, language, country;
 * - `products`: one entry per product (not per variant), reference = product code, the reviews
 *   being about the product; service lines left out (excluded codes, item flag);
 * - `sales_channel`: online, on the configured website;
 * - `solicitation_parameters` left out: the Skeepers portal applies its own delays.
 *
 * Empty values are left out rather than sent as null.
 */
final class PurchaseEventBuilder implements PurchaseEventBuilderInterface
{
    private readonly PropertyAccessorInterface $accessor;

    /**
     * @param list<string> $excludedProductCodes
     */
    public function __construct(
        private readonly ProductLinkResolverInterface $links,
        private readonly ?string $websiteId,
        private readonly array $excludedProductCodes = [],
        private readonly ?string $excludedItemFlag = null,
        private readonly ?string $language = null,
        private readonly ?string $brandPath = null,
        private readonly ?string $defaultBrand = null,
        private readonly ?string $eanPath = null,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessorBuilder()->disableExceptionOnInvalidPropertyPath()->getPropertyAccessor();
    }

    public function build(OrderInterface $order): array
    {
        $billing = $order->getBillingAddress() ?? $order->getShippingAddress();
        $customer = $order->getCustomer();

        $consumer = self::clean([
            'first_name' => self::text($billing?->getFirstName()) ?? self::text($customer?->getFirstName()),
            'last_name' => self::text($billing?->getLastName()) ?? self::text($customer?->getLastName()),
            'email' => self::text($customer?->getEmail()),
            'phone' => self::text($billing?->getPhoneNumber()) ?? self::text($customer?->getPhoneNumber()),
            'language' => $this->language ?? self::language($order->getLocaleCode()),
            'country' => self::country($billing, $order->getShippingAddress()),
        ]);

        $event = [
            'purchase_reference' => (string) $order->getNumber(),
            'purchase_date' => self::date($order->getCheckoutCompletedAt() ?? $order->getCreatedAt()),
            'price' => round($order->getTotal() / 100, 2),
            'consumer' => $consumer,
        ];
        $products = $this->products($order);
        if ($products !== []) {
            $event['products'] = $products;
        }
        $event['sales_channel'] = self::clean(['channel' => 'online', 'website_id' => $this->websiteId]);

        return $event;
    }

    /** @return list<array<string, mixed>> */
    private function products(OrderInterface $order): array
    {
        $out = [];
        /** @var array<string, list<string>> $eans */
        $eans = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$item instanceof OrderItemInterface || !$product instanceof ProductInterface || $this->isExcluded($item, $product)) {
                continue;
            }
            $code = (string) $product->getCode();
            $ean = $this->eanPath !== null && $item->getVariant() !== null ? self::text($this->read($item->getVariant(), $this->eanPath)) : null;
            if ($ean !== null && !\in_array($ean, $eans[$code] ?? [], true)) {
                $eans[$code][] = $ean;
            }
            if (isset($out[$code])) {
                continue; // one entry per product: the first line gives the price
            }
            $out[$code] = self::clean([
                'product_ref' => ['reference' => $code],
                'name' => self::text($item->getProductName()) ?? self::text($product->getTranslation($order->getLocaleCode())->getName()),
                'brand' => $this->brand($product),
                'price' => $item->getQuantity() > 0 ? round($item->getTotal() / $item->getQuantity() / 100, 2) : null,
                'product_url' => $this->links->productUrl($product, $order),
                'image_url' => $this->links->imageUrl($product, $order),
            ]);
        }
        // The EAN goes with the product only when every ordered variant of it has the same one:
        // the variants of a product may carry different codes, the review is about the product.
        foreach ($eans as $code => $list) {
            if (\count($list) === 1) {
                $out[$code]['product_ref']['ean'] = $list[0];
            }
        }

        return array_values($out);
    }

    private function isExcluded(OrderItemInterface $item, ProductInterface $product): bool
    {
        if (\in_array($product->getCode(), $this->excludedProductCodes, true)) {
            return true;
        }

        return $this->excludedItemFlag !== null && $this->read($item, $this->excludedItemFlag) === true;
    }

    private function brand(ProductInterface $product): ?string
    {
        $brand = $this->brandPath !== null ? $this->read($product, $this->brandPath) : null;

        return self::text($brand) ?? self::text($this->defaultBrand);
    }

    private function read(object $object, string $path): mixed
    {
        return $this->accessor->isReadable($object, $path) ? $this->accessor->getValue($object, $path) : null;
    }

    private static function date(?\DateTimeInterface $date): string
    {
        $date ??= new \DateTimeImmutable();

        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function language(?string $locale): ?string
    {
        return $locale ? strtolower(explode('_', str_replace('-', '_', $locale))[0]) : null;
    }

    private static function country(?AddressInterface ...$addresses): ?string
    {
        foreach ($addresses as $address) {
            if ($address !== null && $address->getCountryCode()) {
                return strtoupper($address->getCountryCode());
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function clean(array $data): array
    {
        return array_filter($data, static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
