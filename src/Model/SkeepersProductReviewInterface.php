<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Model;

interface SkeepersProductReviewInterface
{
    public function getAuthorName(): ?string;

    public function setAuthorName(?string $authorName): void;

    public function getExternalId(): ?string;

    public function setExternalId(?string $externalId): void;
}
