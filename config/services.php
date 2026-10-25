<?php

declare(strict_types=1);

use Altmenorg\SyliusSkeepersPlugin\Client\TokenProvider;
use Altmenorg\SyliusSkeepersPlugin\Command\EnqueuePurchaseEventsCommand;
use Altmenorg\SyliusSkeepersPlugin\Command\SendPurchaseEventsCommand;
use Altmenorg\SyliusSkeepersPlugin\Command\SyncProductReviewsCommand;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\ProductLinkResolverInterface;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventBuilder;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventBuilderInterface;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventQueue;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventSender;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\RouteProductLinkResolver;
use Altmenorg\SyliusSkeepersPlugin\Review\DefaultReviewProductResolver;
use Altmenorg\SyliusSkeepersPlugin\Review\ProductReviewSynchronizer;
use Altmenorg\SyliusSkeepersPlugin\Review\ReviewProductResolverInterface;
use Altmenorg\SyliusSkeepersPlugin\Twig\SkeepersExtension;
use Altmenorg\SyliusSkeepersPlugin\Widget\WidgetRenderer;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->private();
    $p = static fn (string $name) => param('altmenorg_sylius_skeepers.' . $name);

    $services->set('altmenorg_sylius_skeepers.token_provider', TokenProvider::class)
        ->args([service('http_client'), $p('client_id'), $p('client_secret'), $p('token_url'), $p('timeout')]);
    $services->alias(TokenProvider::class, 'altmenorg_sylius_skeepers.token_provider');

    // purchase events
    $services->set('altmenorg_sylius_skeepers.purchase_events.link_resolver', RouteProductLinkResolver::class)
        ->args([service('router'), $p('purchase_events.product_route'), $p('purchase_events.image_base_url')]);
    $services->alias(ProductLinkResolverInterface::class, 'altmenorg_sylius_skeepers.purchase_events.link_resolver');

    $services->set('altmenorg_sylius_skeepers.purchase_events.builder', PurchaseEventBuilder::class)
        ->args([
            service(ProductLinkResolverInterface::class),
            $p('purchase_events.website_id'),
            $p('purchase_events.excluded_product_codes'),
            $p('purchase_events.excluded_item_flag'),
            $p('purchase_events.language'),
            $p('purchase_events.brand_path'),
            $p('purchase_events.default_brand'),
            $p('purchase_events.ean_path'),
        ]);
    $services->alias(PurchaseEventBuilderInterface::class, 'altmenorg_sylius_skeepers.purchase_events.builder');

    $services->set('altmenorg_sylius_skeepers.purchase_events.queue', PurchaseEventQueue::class)
        ->args([service('doctrine.dbal.default_connection'), $p('purchase_events.enabled')]);
    $services->alias(PurchaseEventQueue::class, 'altmenorg_sylius_skeepers.purchase_events.queue')->public();

    $services->set('altmenorg_sylius_skeepers.purchase_events.sender', PurchaseEventSender::class)
        ->args([
            service('http_client'),
            service('altmenorg_sylius_skeepers.token_provider'),
            $p('api_url'),
            $p('purchase_events.website_id'),
            $p('purchase_events.audience'),
            $p('purchase_events.batch_size'),
            $p('timeout'),
        ]);

    $services->set('altmenorg_sylius_skeepers.command.send_purchase_events', SendPurchaseEventsCommand::class)
        ->args([
            service('altmenorg_sylius_skeepers.purchase_events.queue'),
            service(PurchaseEventBuilderInterface::class),
            service('altmenorg_sylius_skeepers.purchase_events.sender'),
            service('sylius.repository.order'),
            $p('purchase_events.batch_size'),
            $p('purchase_events.max_order_age_days'),
        ])
        ->tag('console.command');

    $services->set('altmenorg_sylius_skeepers.command.enqueue_purchase_events', EnqueuePurchaseEventsCommand::class)
        ->args([service('altmenorg_sylius_skeepers.purchase_events.queue'), service('sylius.repository.order')])
        ->tag('console.command');

    // product reviews
    $services->set('altmenorg_sylius_skeepers.product_reviews.resolver', DefaultReviewProductResolver::class)
        ->args([
            service('doctrine.dbal.default_connection'),
            $p('product_reviews.match_by_url'),
            $p('product_reviews.locale'),
            $p('product_reviews.url_pattern'),
        ]);
    $services->alias(ReviewProductResolverInterface::class, 'altmenorg_sylius_skeepers.product_reviews.resolver');

    $services->set('altmenorg_sylius_skeepers.product_reviews.synchronizer', ProductReviewSynchronizer::class)
        ->args([
            service('doctrine.dbal.default_connection'),
            service('http_client'),
            service('altmenorg_sylius_skeepers.token_provider'),
            service(ReviewProductResolverInterface::class),
            $p('api_url'),
            $p('product_reviews.path'),
            $p('product_reviews.audience'),
            $p('product_reviews.bot_customer'),
            $p('product_reviews.page_size'),
            $p('product_reviews.page_delay_ms'),
            $p('timeout'),
        ]);
    $services->alias(ProductReviewSynchronizer::class, 'altmenorg_sylius_skeepers.product_reviews.synchronizer');

    $services->set('altmenorg_sylius_skeepers.command.sync_product_reviews', SyncProductReviewsCommand::class)
        ->args([service('altmenorg_sylius_skeepers.product_reviews.synchronizer'), $p('product_reviews.raw_dir')])
        ->tag('console.command');

    // widgets
    $services->set('altmenorg_sylius_skeepers.widget.renderer', WidgetRenderer::class)
        ->args([$p('widgets')]);
    $services->alias(WidgetRenderer::class, 'altmenorg_sylius_skeepers.widget.renderer');

    $services->set('altmenorg_sylius_skeepers.twig.extension', SkeepersExtension::class)
        ->args([service('altmenorg_sylius_skeepers.widget.renderer')])
        ->tag('twig.extension');
};
