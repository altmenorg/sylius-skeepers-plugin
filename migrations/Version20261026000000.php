<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Queue of the purchase events, and the two review columns used by the review import. Each
 * part is only added if missing, so that a shop which already has the review columns (same
 * names and types) adopts the plugin without a data migration.
 */
final class Version20261026000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Skeepers: purchase event queue, external_id and author_name on sylius_product_review.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('altmenorg_skeepers_purchase_event')) {
            $table = $schema->createTable('altmenorg_skeepers_purchase_event');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('order_id', Types::INTEGER);
            $table->addColumn('order_number', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('state', Types::STRING, ['length' => 16]);
            $table->addColumn('attempts', Types::INTEGER, ['default' => 0]);
            $table->addColumn('payload', Types::TEXT, ['notnull' => false]);
            $table->addColumn('response', Types::TEXT, ['notnull' => false]);
            $table->addColumn('skeepers_id', Types::STRING, ['length' => 64, 'notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
            $table->addColumn('sent_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['order_id'], 'UNIQ_SKEEPERS_PE_ORDER');
            $table->addIndex(['state'], 'IDX_SKEEPERS_PE_STATE');
        }

        $review = $schema->getTable('sylius_product_review');
        if (!$review->hasColumn('author_name')) {
            $review->addColumn('author_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        }
        if (!$review->hasColumn('external_id')) {
            $review->addColumn('external_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        $unique = false;
        foreach ($review->getIndexes() as $index) {
            $unique = $unique || ($index->isUnique() && $index->getColumns() === ['external_id']);
        }
        if (!$unique) {
            // the name Doctrine gives to `unique: true` on this column
            $review->addUniqueIndex(['external_id'], 'UNIQ_C7056A999F75D7B0');
        }
    }

    public function down(Schema $schema): void
    {
        // The review columns stay: the shop may have had them before the plugin.
        if ($schema->hasTable('altmenorg_skeepers_purchase_event')) {
            $schema->dropTable('altmenorg_skeepers_purchase_event');
        }
    }
}
