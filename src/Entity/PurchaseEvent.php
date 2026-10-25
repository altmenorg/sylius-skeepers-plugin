<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One order to send to Skeepers, and what became of it. One row per order (unique `order_id`):
 * an order is never sent twice.
 *
 * The queue reads and writes this table through DBAL (`PurchaseEventQueue`), so that an order
 * can be queued from inside a Doctrine flush; the mapping keeps the schema tool in step.
 */
#[ORM\Entity]
#[ORM\Table(name: 'altmenorg_skeepers_purchase_event')]
#[ORM\Index(name: 'IDX_SKEEPERS_PE_STATE', columns: ['state'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SKEEPERS_PE_ORDER', columns: ['order_id'])]
class PurchaseEvent
{
    public const STATE_PENDING = 'pending';
    public const STATE_SENT = 'sent';
    public const STATE_ERROR = 'error';
    public const STATE_SKIPPED = 'skipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'order_id', type: 'integer')]
    private int $orderId = 0;

    #[ORM\Column(name: 'order_number', type: 'string', length: 255, nullable: true)]
    private ?string $orderNumber = null;

    #[ORM\Column(name: 'state', type: 'string', length: 16)]
    private string $state = self::STATE_PENDING;

    #[ORM\Column(name: 'attempts', type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    /** Purchase event sent (JSON), kept for checking. */
    #[ORM\Column(name: 'payload', type: 'text', nullable: true)]
    private ?string $payload = null;

    /** HTTP status and answer of Skeepers, or the reason of a skip. */
    #[ORM\Column(name: 'response', type: 'text', nullable: true)]
    private ?string $response = null;

    /** Purchase event ID given by Skeepers. */
    #[ORM\Column(name: 'skeepers_id', type: 'string', length: 64, nullable: true)]
    private ?string $skeepersId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrderId(): int { return $this->orderId; }
    public function getOrderNumber(): ?string { return $this->orderNumber; }
    public function getState(): string { return $this->state; }
    public function getAttempts(): int { return $this->attempts; }
    public function getPayload(): ?string { return $this->payload; }
    public function getResponse(): ?string { return $this->response; }
    public function getSkeepersId(): ?string { return $this->skeepersId; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function getSentAt(): ?\DateTimeInterface { return $this->sentAt; }
}
