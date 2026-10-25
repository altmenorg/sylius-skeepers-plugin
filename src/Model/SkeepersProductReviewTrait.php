<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Columns of `sylius_product_review` used by the review import: the review ID at Skeepers
 * (unique, so that a review is updated rather than duplicated) and the name as Skeepers
 * publishes it ("Jane D.").
 */
trait SkeepersProductReviewTrait
{
    #[ORM\Column(name: 'author_name', type: 'string', length: 255, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'external_id', type: 'string', length: 64, unique: true, nullable: true)]
    private ?string $externalId = null;

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): void
    {
        $this->authorName = $authorName;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId;
    }
}
