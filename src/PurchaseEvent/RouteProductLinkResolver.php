<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Default links: the shop route of the product (slug in the order locale) on the hostname of
 * the order channel, and the first image path behind `image_base_url`.
 */
final class RouteProductLinkResolver implements ProductLinkResolverInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly string $route = 'sylius_shop_product_show',
        private readonly ?string $imageBaseUrl = null,
    ) {
    }

    public function productUrl(ProductInterface $product, OrderInterface $order): ?string
    {
        $locale = $order->getLocaleCode() ?? 'en_US';
        $slug = $product->getTranslation($locale)->getSlug();
        $host = $order->getChannel()?->getHostname();
        if ($slug === null || $slug === '' || $host === null || $host === '') {
            return null;
        }

        $context = $this->router->getContext();
        $saved = clone $context;
        $this->router->setContext(new RequestContext('', 'GET', $host, 'https'));
        try {
            return $this->router->generate($this->route, ['slug' => $slug, '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RoutingException) {
            return null;
        } finally {
            $this->router->setContext($saved);
        }
    }

    public function imageUrl(ProductInterface $product, OrderInterface $order): ?string
    {
        if ($this->imageBaseUrl === null || $this->imageBaseUrl === '') {
            return null;
        }
        $image = $product->getImagesByType('main')->first() ?: $product->getImages()->first();
        $path = $image ? $image->getPath() : null;

        return $path ? rtrim($this->imageBaseUrl, '/') . '/' . ltrim($path, '/') : null;
    }
}
