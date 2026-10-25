<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Command;

use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventQueue;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queues orders by number, for the "manual" trigger or to catch up an order the trigger missed.
 * An order already queued (sent or not) is left alone.
 *
 *   skeepers:purchase-events:enqueue <number>...
 */
#[AsCommand(name: 'skeepers:purchase-events:enqueue', description: 'Queue orders (by number) for Skeepers')]
final class EnqueuePurchaseEventsCommand extends Command
{
    public function __construct(
        private readonly PurchaseEventQueue $queue,
        private readonly OrderRepositoryInterface $orders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('numbers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'order numbers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->queue->isEnabled()) {
            $output->writeln('Purchase events are disabled (altmenorg_sylius_skeepers.purchase_events.enabled: false): nothing queued.');

            return Command::SUCCESS;
        }
        foreach ((array) $input->getArgument('numbers') as $number) {
            $order = $this->orders->findOneBy(['number' => (string) $number]);
            if (!$order instanceof OrderInterface) {
                $output->writeln(sprintf('Order %s: not found.', $number));
                continue;
            }
            $output->writeln(sprintf('Order %s: %s.', $number, $this->queue->enqueue($order) ? 'queued' : 'already queued'));
        }

        return Command::SUCCESS;
    }
}
