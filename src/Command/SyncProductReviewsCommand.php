<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Command;

use Altmenorg\SyliusSkeepersPlugin\Review\ProductReviewSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports the published product reviews of Skeepers.
 *
 *   skeepers:product-reviews:sync [--since=YYYY-MM-DD] [--raw-dir=<dir>] [--dry-run]
 */
#[AsCommand(name: 'skeepers:product-reviews:sync', description: 'Import the published product reviews of Skeepers (Verified Reviews)')]
final class SyncProductReviewsCommand extends Command
{
    public function __construct(
        private readonly ProductReviewSynchronizer $synchronizer,
        private readonly ?string $rawDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'only read the reviews published since this date')
            ->addOption('raw-dir', null, InputOption::VALUE_REQUIRED, 'where to keep the raw answers (JSON lines)', $this->rawDir)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'read and count, write nothing in the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $input->getOption('since') ? new \DateTimeImmutable((string) $input->getOption('since')) : null;
        $stats = $this->synchronizer->sync($since, (bool) $input->getOption('dry-run'), $input->getOption('raw-dir') ?: null);
        self::report($stats, $output);

        return Command::SUCCESS;
    }

    /** @param array{read: int, kept: int, linked: int, syndicated: int, unknown: int, unknown_refs: list<string>} $stats */
    public static function report(array $stats, OutputInterface $output, bool $french = false): void
    {
        $output->writeln(sprintf(
            $french
                ? '%d avis lus, %d repris (%d rattachés au client de la commande), %d syndiqués écartés, %d sans produit connu.'
                : '%d reviews read, %d kept (%d linked to the customer of the order), %d syndicated left out, %d without a known product.',
            $stats['read'], $stats['kept'], $stats['linked'], $stats['syndicated'], $stats['unknown'],
        ));
        if ($stats['unknown_refs'] !== []) {
            $output->writeln(($french ? 'Réf. inconnues : ' : 'Unknown references: ') . implode(', ', \array_slice($stats['unknown_refs'], 0, 30)));
        }
    }
}
