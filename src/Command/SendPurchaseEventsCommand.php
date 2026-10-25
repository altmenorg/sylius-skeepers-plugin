<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Command;

use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventBuilderInterface;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventQueue;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventSender;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends the queued orders to Skeepers. Meant for a cron (every few minutes) or a Messenger
 * scheduler.
 *
 *   skeepers:purchase-events:send [--limit=200] [--dry-run] [--order=<number>]... [--retry-errors] [--status]
 *
 * `--dry-run` prints the JSON that would be sent and changes nothing; with `--order`, it builds
 * the given orders instead of the queue (they need not be queued).
 */
#[AsCommand(name: 'skeepers:purchase-events:send', description: 'Send the queued orders to Skeepers (review requests)')]
final class SendPurchaseEventsCommand extends Command
{
    public function __construct(
        private readonly PurchaseEventQueue $queue,
        private readonly PurchaseEventBuilderInterface $builder,
        private readonly PurchaseEventSender $sender,
        private readonly OrderRepositoryInterface $orders,
        private readonly int $batchSize = 50,
        private readonly ?int $maxOrderAgeDays = 90,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'orders taken from the queue in this run', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'print the JSON that would be sent, send nothing, change nothing')
            ->addOption('order', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'with --dry-run: build these orders (number) instead of the queue')
            ->addOption('retry-errors', null, InputOption::VALUE_NONE, 'put the orders in error back in the queue first (check in the portal that they did not arrive)')
            ->addOption('status', null, InputOption::VALUE_NONE, 'print the number of orders per state and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry = (bool) $input->getOption('dry-run');
        /** @var list<string> $numbers */
        $numbers = $input->getOption('order');

        if ($input->getOption('status')) {
            foreach ($this->queue->counts() as $state => $n) {
                $output->writeln(sprintf('%-8s %d', $state, $n));
            }

            return Command::SUCCESS;
        }
        if ($numbers !== []) {
            if (!$dry) {
                $output->writeln('<error>--order only works with --dry-run. To send an order, queue it: skeepers:purchase-events:enqueue.</error>');

                return Command::INVALID;
            }

            return $this->dryRunOrders($numbers, $output);
        }
        if (!$dry && !$this->queue->isEnabled()) {
            $output->writeln('Purchase events are disabled (altmenorg_sylius_skeepers.purchase_events.enabled: false): nothing sent.');

            return Command::SUCCESS;
        }
        if (!$dry && $input->getOption('retry-errors')) {
            $output->writeln(sprintf('%d order(s) in error put back in the queue.', $this->queue->retryErrors()));
        }

        $events = [];
        $numbersById = [];
        foreach ($this->queue->pending(max(1, (int) $input->getOption('limit'))) as $row) {
            $order = $this->orders->find($row['order_id']);
            $skip = $this->skipReason($order);
            if ($skip !== null) {
                $output->writeln(sprintf('Order %s skipped: %s.', $row['order_number'] ?? '#' . $row['order_id'], $skip));
                if (!$dry) {
                    $this->queue->markSkipped($row['id'], $skip);
                }
                continue;
            }
            /** @var OrderInterface $order */
            $events[$row['id']] = $this->builder->build($order);
            $numbersById[$row['id']] = (string) $order->getNumber();
        }

        if ($dry) {
            $this->printBatches($events, $output);

            return Command::SUCCESS;
        }
        if ($events === []) {
            $output->writeln('Nothing to send.');

            return Command::SUCCESS;
        }

        $counts = [PurchaseEventSender::SENT => 0, PurchaseEventSender::ERROR => 0, PurchaseEventSender::DEFERRED => 0];
        foreach ($this->sender->send($events) as $id => $result) {
            $payload = json_encode($events[$id], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';
            match ($result['state']) {
                PurchaseEventSender::SENT => $this->queue->markSent($id, $payload, $result['response'], $result['skeepers_id']),
                PurchaseEventSender::DEFERRED => $this->queue->markDeferred($id, $result['response']),
                default => $this->queue->markError($id, $result['response'], $payload),
            };
            ++$counts[$result['state']];
            if ($result['state'] !== PurchaseEventSender::SENT) {
                $output->writeln(sprintf('Order %s: %s, %s', $numbersById[$id], $result['state'], mb_substr($result['response'], 0, 300)));
            }
        }
        $output->writeln(sprintf('%d sent, %d in error, %d left pending (rate limit), %d not tried.',
            $counts[PurchaseEventSender::SENT], $counts[PurchaseEventSender::ERROR], $counts[PurchaseEventSender::DEFERRED],
            \count($events) - array_sum($counts)));

        return $counts[PurchaseEventSender::ERROR] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<string> $numbers */
    private function dryRunOrders(array $numbers, OutputInterface $output): int
    {
        $events = [];
        foreach ($numbers as $number) {
            $order = $this->orders->findOneBy(['number' => $number]);
            $skip = $this->skipReason($order);
            if ($skip !== null) {
                $output->writeln(sprintf('Order %s would be skipped: %s.', $number, $skip));
                if (!$order instanceof OrderInterface) {
                    continue;
                }
            }
            /** @var OrderInterface $order */
            $events[] = $this->builder->build($order);
        }
        $this->printBatches($events, $output);

        return Command::SUCCESS;
    }

    /** @param array<array-key, array<string, mixed>> $events */
    private function printBatches(array $events, OutputInterface $output): void
    {
        $output->writeln(sprintf('Dry run: %d purchase event(s), nothing sent.', \count($events)));
        foreach (PurchaseEventSender::batches($events, $this->batchSize) as $i => $keys) {
            $batch = array_values(array_intersect_key($events, array_flip($keys)));
            $output->writeln(sprintf('--- request %d (%d event(s))', $i + 1, \count($batch)));
            $output->writeln(json_encode($batch, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '[]');
        }
    }

    private function skipReason(mixed $order): ?string
    {
        if (!$order instanceof OrderInterface) {
            return 'order not found';
        }
        if (!$order->getCustomer()?->getEmail()) {
            return 'no customer email';
        }
        $date = $order->getCheckoutCompletedAt();
        if ($date === null) {
            return 'order not completed';
        }
        if ($this->maxOrderAgeDays !== null && $date < new \DateTimeImmutable(sprintf('-%d days', $this->maxOrderAgeDays))) {
            return sprintf('placed more than %d days ago', $this->maxOrderAgeDays);
        }

        return null;
    }
}
