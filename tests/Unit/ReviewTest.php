<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Unit;

use Altmenorg\SyliusSkeepersPlugin\Review\DefaultReviewProductResolver;
use Altmenorg\SyliusSkeepersPlugin\Review\ProductReviewSynchronizer;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase
{
    public function testAuthorName(): void
    {
        self::assertSame('Magali D.', ProductReviewSynchronizer::authorName(['author_firstname' => ' Magali ', 'author_lastname' => 'Dupont', 'is_personal_data_disclosed' => true]));
        self::assertSame('Anonymous a.', ProductReviewSynchronizer::authorName(['author_firstname' => 'a', 'author_lastname' => 'a', 'is_personal_data_disclosed' => false]));
        self::assertSame('a a.', ProductReviewSynchronizer::authorName(['author_firstname' => 'a', 'author_lastname' => 'a', 'is_personal_data_disclosed' => true]));
        self::assertSame('Jean', ProductReviewSynchronizer::authorName(['author_firstname' => 'Jean']));
    }

    public function testSlugOfTheProductPage(): void
    {
        $pattern = '#/([^/]+)\.html$#';
        self::assertSame('curcuma-bio', DefaultReviewProductResolver::slug('https://www.example.com/epices/curcuma-bio.html', $pattern));
        self::assertNull(DefaultReviewProductResolver::slug('https://www.example.com/epices/', $pattern));
        self::assertNull(DefaultReviewProductResolver::slug('', $pattern));
    }
}
