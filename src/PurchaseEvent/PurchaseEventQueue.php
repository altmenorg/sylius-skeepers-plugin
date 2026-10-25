<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Altmenorg\SyliusSkeepersPlugin\Entity\PurchaseEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Orders waiting to be sent to Skeepers (table `altmenorg_skeepers_purchase_event`).
 *
 * Plain DBAL statements: `enqueue()` may be called from a Doctrine listener (postFlush), where
 * a second ORM flush is not safe. Queuing an order twice does nothing (unique `order_id`).
 * While the plugin is disabled, nothing is queued: switching it on later does not release a
 * backlog of old orders to Skeepers.
 */
final class PurchaseEventQueue
{
    public const TABLE = 'altmenorg_skeepers_purchase_event';

    public function __construct(
        private readonly Connection $db,
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return bool true when the order was queued now, false when disabled, not saved yet or already queued
     */
    public function enqueue(OrderInterface $order): bool
    {
        if (!$this->enabled || $order->getId() === null) {
            return false;
        }
        try {
            $this->db->insert(self::TABLE, [
                'order_id' => (int) $order->getId(),
                'order_number' => $order->getNumber(),
                'state' => PurchaseEvent::STATE_PENDING,
                'attempts' => 0,
                'created_at' => self::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<array{id: int, order_id: int, order_number: ?string}>
     */
    public function pending(int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, order_id, order_number FROM ' . self::TABLE . ' WHERE state = ? ORDER BY id LIMIT ' . max(1, $limit),
            [PurchaseEvent::STATE_PENDING],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'order_id' => (int) $r['order_id'], 'order_number' => $r['order_number'],
        ], $rows);
    }

    public function markSent(int $id, string $payload, string $response, ?string $skeepersId): void
    {
        $this->update($id, PurchaseEvent::STATE_SENT, $response, $payload, ['skeepers_id' => $skeepersId, 'sent_at' => self::now()]);
    }

    public function markError(int $id, string $response, ?string $payload): void
    {
        $this->update($id, PurchaseEvent::STATE_ERROR, $response, $payload);
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->update($id, PurchaseEvent::STATE_SKIPPED, $reason, null);
    }

    /** Counts an attempt that did not reach Skeepers (rate limit): the row stays pending. */
    public function markDeferred(int $id, string $response): void
    {
        $this->update($id, PurchaseEvent::STATE_PENDING, $response, null);
    }

    /** Puts the rows in error back in the queue (after checking in the portal that they did not arrive). */
    public function retryErrors(): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE ' . self::TABLE . ' SET state = ?, updated_at = ? WHERE state = ?',
            [PurchaseEvent::STATE_PENDING, self::now(), PurchaseEvent::STATE_ERROR],
        );
    }

    /** @return array<string, int> rows per state */
    public function counts(): array
    {
        return array_map('intval', $this->db->fetchAllKeyValue('SELECT state, COUNT(*) FROM ' . self::TABLE . ' GROUP BY state'));
    }

    /** @param array<string, mixed> $extra */
    private function update(int $id, string $state, string $response, ?string $payload, array $extra = []): void
    {
        $data = ['state' => $state, 'response' => mb_substr($response, 0, 60000), 'updated_at' => self::now()] + $extra;
        if ($payload !== null) {
            $data['payload'] = $payload;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($data)));
        $this->db->executeStatement(
            'UPDATE ' . self::TABLE . " SET $sets, attempts = attempts + 1 WHERE id = ?",
            [...array_values($data), $id],
        );
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
