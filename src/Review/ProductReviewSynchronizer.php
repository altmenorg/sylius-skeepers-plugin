<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Review;

use Altmenorg\SyliusSkeepersPlugin\Client\SkeepersException;
use Altmenorg\SyliusSkeepersPlugin\Client\TokenProvider;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies the published product reviews of Skeepers (verified-reviews API) into `sylius_product_review`:
 *
 * - every published review, `page_size` per page, next page from the `Link` header; with a
 *   "since" date, stops at the first review older than it (the API lists the newest first);
 * - syndicated reviews (from other websites of the same brand) left out;
 * - product found by `ReviewProductResolverInterface`; reviews without a known product counted
 *   and skipped;
 * - author: the customer of the reviewed order (`order_reference` = order number) when the
 *   order is in the shop, else a "robot" customer created once; the published name
 *   ("Jane D.") kept in `author_name`, "Anonymous" when the author chose not to disclose it;
 * - a review already there (same `external_id`) is updated, never duplicated;
 * - then the average rating and review count of each reviewed product computed again from
 *   its accepted reviews.
 *
 * Needs the columns `external_id` (unique) and `author_name` on `sylius_product_review`
 * (`SkeepersProductReviewTrait`, added by the plugin migration when missing).
 */
final class ProductReviewSynchronizer
{
    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param array{email: string, first_name: string, last_name: string} $botCustomer
     */
    public function __construct(
        private readonly Connection $db,
        private readonly HttpClientInterface $http,
        private readonly TokenProvider $tokens,
        private readonly ReviewProductResolverInterface $resolver,
        private readonly string $apiUrl,
        private readonly string $path,
        private readonly ?string $audience,
        private readonly array $botCustomer,
        private readonly int $pageSize = 200,
        private readonly int $pageDelayMs = 200,
        private readonly int $timeout = 30,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void { usleep($ms * 1000); };
    }

    /**
     * @return array{read: int, kept: int, linked: int, syndicated: int, unknown: int, unknown_refs: list<string>}
     */
    public function sync(?\DateTimeImmutable $since = null, bool $dryRun = false, ?string $rawDir = null): array
    {
        $raw = null;
        if ($rawDir !== null && $rawDir !== '') {
            @mkdir($rawDir, 0700, true);
            $raw = fopen(rtrim($rawDir, '/') . '/skeepers-' . date('Ymd-His') . '.jsonl', 'w') ?: null;
        }

        $orders = null; // loaded on the first review with a product
        $bot = $dryRun ? 0 : $this->botCustomer();
        $url = rtrim($this->apiUrl, '/') . $this->path . '?limit=' . $this->pageSize;
        $stats = ['read' => 0, 'kept' => 0, 'linked' => 0, 'syndicated' => 0, 'unknown' => 0, 'unknown_refs' => []];
        $unknownRefs = [];
        $renewed = false;
        try {
            while ($url !== null) {
                $res = $this->http->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer ' . $this->tokens->token($this->audience, $renewed), 'Accept' => 'application/json'],
                    'timeout' => $this->timeout,
                ]);
                if ($res->getStatusCode() === 401 && !$renewed) {
                    $renewed = true;
                    continue;
                }
                if ($res->getStatusCode() !== 200) {
                    throw new SkeepersException(sprintf('Skeepers reviews: HTTP %d on %s', $res->getStatusCode(), $url));
                }
                $renewed = false;
                $page = $res->toArray();
                $url = null;
                if (preg_match('/<([^>]+)>;\s*rel="next"/', (string) ($res->getHeaders()['link'][0] ?? ''), $m)) {
                    $url = $m[1];
                }
                $stop = false;
                foreach ($page as $r) {
                    ++$stats['read'];
                    if ($raw) {
                        fwrite($raw, json_encode($r, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n");
                    }
                    $date = new \DateTimeImmutable((string) $r['review_date']);
                    if ($since !== null && $date < $since) {
                        $stop = true;
                        break;
                    }
                    if (!empty($r['syndicated_review'])) {
                        ++$stats['syndicated'];
                        continue;
                    }
                    $pid = $this->resolver->resolve($r);
                    if ($pid === null) {
                        ++$stats['unknown'];
                        $unknownRefs[(string) ($r['product_variation_id'] ?? '')] = true;
                        continue;
                    }
                    $orders ??= array_map('intval', $this->db->fetchAllKeyValue(
                        'SELECT number, customer_id FROM sylius_order WHERE number IS NOT NULL AND customer_id IS NOT NULL'
                    ));
                    $customer = $orders[(string) ($r['order_reference'] ?? '')] ?? null;
                    $stats['linked'] += (int) ($customer !== null);
                    ++$stats['kept'];
                    if (!$dryRun) {
                        $this->write($pid, $customer ?? $bot, $r, $date);
                    }
                }
                if ($stop) {
                    break;
                }
                if ($url !== null && $this->pageDelayMs > 0) {
                    ($this->sleep)($this->pageDelayMs);
                }
            }
        } finally {
            if ($raw) {
                fclose($raw);
            }
        }

        if (!$dryRun) {
            // average rating and review count, as Sylius keeps them (accepted reviews)
            $this->db->executeStatement(
                'UPDATE sylius_product p
                 JOIN (SELECT product_id, AVG(rating) a, COUNT(*) c FROM sylius_product_review WHERE status = \'accepted\' GROUP BY product_id) r ON r.product_id = p.id
                 SET p.average_rating = ROUND(r.a, 2), p.reviews_count = r.c'
            );
        }
        $stats['unknown_refs'] = array_map('strval', array_keys($unknownRefs));

        return $stats;
    }

    /** "Jane D.": first name and initial of the last name, as Skeepers publishes it. */
    public static function authorName(array $review): string
    {
        $last = trim((string) ($review['author_lastname'] ?? ''));
        $first = trim((string) ($review['author_firstname'] ?? ''));
        // undisclosed author: Skeepers gives "a a"
        if (empty($review['is_personal_data_disclosed']) && $first === 'a' && $last === 'a') {
            $first = 'Anonymous';
        }

        return trim($first . ' ' . ($last !== '' ? mb_substr($last, 0, 1) . '.' : ''));
    }

    /** @param array<string, mixed> $r */
    private function write(int $productId, int $authorId, array $r, \DateTimeImmutable $date): void
    {
        $this->db->executeStatement(
            'INSERT INTO sylius_product_review (product_id, author_id, title, rating, comment, status, created_at, updated_at, author_name, external_id)
             VALUES (?, ?, NULL, ?, ?, \'accepted\', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), author_id = VALUES(author_id), rating = VALUES(rating),
                comment = VALUES(comment), created_at = VALUES(created_at), updated_at = VALUES(updated_at), author_name = VALUES(author_name)',
            [
                $productId, $authorId, (int) $r['review_rate'], (string) ($r['review_content'] ?? ''),
                // time as Skeepers publishes it
                $date->format('Y-m-d H:i:s'), (new \DateTimeImmutable((string) ($r['publish_date'] ?? $r['review_date'])))->format('Y-m-d H:i:s'),
                self::authorName($r),
                (string) $r['review_id'],
            ],
        );
    }

    private function botCustomer(): int
    {
        $email = $this->botCustomer['email'];
        $id = $this->db->fetchOne('SELECT id FROM sylius_customer WHERE email_canonical = ?', [mb_strtolower($email)]);
        if ($id) {
            return (int) $id;
        }
        $this->db->insert('sylius_customer', [
            'email' => $email, 'email_canonical' => mb_strtolower($email),
            'first_name' => $this->botCustomer['first_name'], 'last_name' => $this->botCustomer['last_name'],
            'gender' => 'u', 'subscribed_to_newsletter' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }
}
