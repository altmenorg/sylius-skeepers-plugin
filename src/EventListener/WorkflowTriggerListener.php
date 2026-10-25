<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\EventListener;

use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventQueue;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Workflow\Event\Event;

/**
 * Queues the order when the configured Symfony workflow transition completes: "fulfill" of
 * `sylius_order` (trigger `order_fulfilled`) or "ship" of `sylius_shipment` (`shipment_shipped`;
 * an order with several shipments is queued once, at the first one).
 */
final class WorkflowTriggerListener
{
    public function __construct(private readonly PurchaseEventQueue $queue)
    {
    }

    public function __invoke(Event $event): void
    {
        $subject = $event->getSubject();
        $order = match (true) {
            $subject instanceof OrderInterface => $subject,
            $subject instanceof ShipmentInterface => $subject->getOrder(),
            default => null,
        };
        if ($order instanceof OrderInterface) {
            $this->queue->enqueue($order);
        }
    }
}
