<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\DependencyInjection;

use Altmenorg\SyliusSkeepersPlugin\EventListener\WidgetInjectionListener;
use Altmenorg\SyliusSkeepersPlugin\EventListener\WorkflowTriggerListener;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class AltmenorgSyliusSkeepersExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach (['client_id', 'client_secret', 'token_url', 'api_url', 'timeout'] as $key) {
            $container->setParameter('altmenorg_sylius_skeepers.' . $key, $config[$key]);
        }
        foreach ($config['purchase_events'] as $key => $value) {
            $container->setParameter('altmenorg_sylius_skeepers.purchase_events.' . $key, $value);
        }
        foreach ($config['product_reviews'] as $key => $value) {
            $container->setParameter('altmenorg_sylius_skeepers.product_reviews.' . $key, $value);
        }

        $container->setParameter('altmenorg_sylius_skeepers.widgets', $config['widgets']);

        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config')))->load('services.php');

        // The page listener only exists when an enabled widget is placed in "head" or "body_end".
        foreach ($config['widgets'] as $widget) {
            if ($widget['enabled'] && $widget['placement'] !== Configuration::PLACEMENT_MANUAL) {
                $container->register('altmenorg_sylius_skeepers.listener.widget_injection', WidgetInjectionListener::class)
                    ->setArguments([
                        new Reference('altmenorg_sylius_skeepers.widget.renderer'),
                        new Reference(SectionProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    ])
                    ->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => '__invoke', 'priority' => -100]);
                break;
            }
        }

        // The workflow listener only exists for the chosen trigger: "manual" adds nothing.
        $events = match ($config['purchase_events']['trigger']) {
            Configuration::TRIGGER_ORDER_FULFILLED => ['workflow.sylius_order.completed.fulfill'],
            Configuration::TRIGGER_SHIPMENT_SHIPPED => ['workflow.sylius_shipment.completed.ship'],
            default => [],
        };
        if ($events !== []) {
            $listener = $container->register('altmenorg_sylius_skeepers.listener.workflow_trigger', WorkflowTriggerListener::class)
                ->setArguments([new Reference('altmenorg_sylius_skeepers.purchase_events.queue')]);
            foreach ($events as $event) {
                $listener->addTag('kernel.event_listener', ['event' => $event, 'method' => '__invoke']);
            }
        }
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'AltmenorgSyliusSkeepersPlugin' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/Entity',
                        'prefix' => 'Altmenorg\SyliusSkeepersPlugin\Entity',
                        'alias' => 'AltmenorgSyliusSkeepersPlugin',
                    ],
                ],
            ],
        ]);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Altmenorg\SyliusSkeepersPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@AltmenorgSyliusSkeepersPlugin/migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return ['Sylius\Bundle\CoreBundle\Migrations'];
    }
}
