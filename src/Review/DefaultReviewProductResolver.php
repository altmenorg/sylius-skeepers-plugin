<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Review;

use Doctrine\DBAL\Connection;

/**
 * Default rules, in this order:
 *
 * 1. the slug of `product_page_url` (first group of `url_pattern`) in the configured locale,
 *    when `match_by_url` is on: a product code may have changed since the review, its page
 *    address usually not;
 * 2. `product_variation_id` as a product code, then as a variant code (case ignored);
 * 3. a composed reference ("6A1D-5C4C", variants sold together): each part in turn.
 *
 * Codes and slugs are read once per run.
 */
final class DefaultReviewProductResolver implements ReviewProductResolverInterface
{
    /** @var array<string, int>|null */
    private ?array $byCode = null;

    /** @var array<string, int>|null */
    private ?array $bySlug = null;

    public function __construct(
        private readonly Connection $db,
        private readonly bool $matchByUrl = true,
        private readonly string $locale = 'en_US',
        private readonly string $urlPattern = '#/([^/]+)\.html$#',
    ) {
    }

    public function resolve(array $review): ?int
    {
        $this->load();
        $ref = strtolower((string) ($review['product_variation_id'] ?? ''));
        $pid = null;
        if ($this->matchByUrl) {
            $slug = self::slug((string) ($review['product_page_url'] ?? ''), $this->urlPattern);
            $pid = $slug !== null ? $this->bySlug[$slug] ?? null : null;
        }
        $pid ??= $this->byCode[$ref] ?? null;
        foreach ($pid === null ? explode('-', $ref) : [] as $part) {
            $pid ??= $this->byCode[$part] ?? null;
        }

        return $pid;
    }

    public static function slug(string $url, string $pattern): ?string
    {
        return preg_match($pattern, $url, $m) && isset($m[1]) && $m[1] !== '' ? $m[1] : null;
    }

    private function load(): void
    {
        if ($this->byCode !== null) {
            return;
        }
        $this->byCode = [];
        foreach ($this->db->fetchAllKeyValue('SELECT code, id FROM sylius_product') as $code => $id) {
            $this->byCode[strtolower((string) $code)] = (int) $id;
        }
        foreach ($this->db->fetchAllKeyValue('SELECT code, product_id FROM sylius_product_variant') as $code => $id) {
            $this->byCode[strtolower((string) $code)] ??= (int) $id;
        }
        $this->bySlug = [];
        if ($this->matchByUrl) {
            $this->bySlug = array_map('intval', $this->db->fetchAllKeyValue(
                'SELECT slug, translatable_id FROM sylius_product_translation WHERE locale = ?',
                [$this->locale],
            ));
        }
    }
}
