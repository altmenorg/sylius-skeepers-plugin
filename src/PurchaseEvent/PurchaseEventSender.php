<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\PurchaseEvent;

use Altmenorg\SyliusSkeepersPlugin\Client\TokenProvider;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends purchase events to `POST /purchase-event/websites/{website_id}/purchase_events/bulk_sync_insert`,
 * 50 at most per request, and says what became of each one.
 *
 * - 201: all created;
 * - 206: some created, the others refused (`error_description`); the created ones are those the
 *   answer lists with their reference;
 * - 400: nothing created. A batch of several events is sent again one by one, so that one bad
 *   event does not hold back the others;
 * - 401: the token is renewed once;
 * - 429: the batch stays pending and the run stops;
 * - no answer or 5xx: the outcome is unknown (Skeepers may have created the events), the batch is
 *   marked in error and the run stops. Never sent again automatically: check in the portal, then
 *   `--retry-errors`.
 */
final class PurchaseEventSender
{
    public const SENT = 'sent';
    public const ERROR = 'error';
    public const DEFERRED = 'deferred';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TokenProvider $tokens,
        private readonly string $apiUrl,
        private readonly ?string $websiteId,
        private readonly ?string $audience = null,
        private readonly int $batchSize = 50,
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * @template K of array-key
     *
     * @param array<K, array<string, mixed>> $events
     *
     * @return list<list<K>> keys of the events, in batches of $size at most
     */
    public static function batches(array $events, int $size = 50): array
    {
        return array_chunk(array_keys($events), max(1, min(50, $size)));
    }

    /**
     * @param array<int, array<string, mixed>> $events purchase events by queue row ID
     *
     * @return array<int, array{state: string, response: string, skeepers_id: ?string}> outcome by row ID
     *         (rows left out were not tried: the run stopped)
     */
    public function send(array $events): array
    {
        if ($this->websiteId === null || $this->websiteId === '') {
            throw new \LogicException('The Skeepers website ID is not configured (purchase_events.website_id).');
        }
        $results = [];
        foreach (self::batches($events, $this->batchSize) as $keys) {
            $batch = array_intersect_key($events, array_flip($keys));
            if (!$this->sendBatch($batch, $results)) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     * @param array<int, array{state: string, response: string, skeepers_id: ?string}> $results
     *
     * @return bool false when the run must stop
     */
    private function sendBatch(array $batch, array &$results, bool $retried = false): bool
    {
        $url = rtrim($this->apiUrl, '/') . '/purchase-event/websites/' . rawurlencode((string) $this->websiteId) . '/purchase_events/bulk_sync_insert';
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->tokens->token($this->audience, $retried), 'Accept' => 'application/json'],
                'json' => array_values($batch),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $this->all($batch, $results, self::ERROR, 'no answer (outcome unknown, check the portal): ' . $e->getMessage());

            return false;
        }
        $text = 'HTTP ' . $status . ' ' . $body;

        if ($status === 401 && !$retried) {
            return $this->sendBatch($batch, $results, true);
        }
        if ($status === 201 || $status === 200) {
            $ids = self::createdIds($body);
            foreach ($batch as $key => $event) {
                $results[$key] = ['state' => self::SENT, 'response' => $text, 'skeepers_id' => $ids[(string) $event['purchase_reference']] ?? null];
            }

            return true;
        }
        if ($status === 206) {
            $ids = self::createdIds($body);
            $error = self::errorDescription($body);
            foreach ($batch as $key => $event) {
                $ref = (string) $event['purchase_reference'];
                $results[$key] = \array_key_exists($ref, $ids)
                    ? ['state' => self::SENT, 'response' => $text, 'skeepers_id' => $ids[$ref]]
                    : ['state' => self::ERROR, 'response' => ($ids === [] ? 'partial, created ones unknown (check the portal): ' : 'refused: ') . ($error ?? $text), 'skeepers_id' => null];
            }

            return true;
        }
        if ($status === 400 && \count($batch) > 1) {
            foreach ($batch as $key => $event) {
                if (!$this->sendBatch([$key => $event], $results)) {
                    return false;
                }
            }

            return true;
        }
        if ($status === 429) {
            $this->all($batch, $results, self::DEFERRED, $text);

            return false;
        }
        $this->all($batch, $results, self::ERROR, ($status >= 500 ? 'outcome unknown (check the portal): ' : '') . $text);

        return $status < 500 && $status !== 401;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     * @param array<int, array{state: string, response: string, skeepers_id: ?string}> $results
     */
    private function all(array $batch, array &$results, string $state, string $response): void
    {
        foreach (array_keys($batch) as $key) {
            $results[$key] = ['state' => $state, 'response' => $response, 'skeepers_id' => null];
        }
    }

    /** @return array<string, ?string> Skeepers ID by purchase reference, for the events the answer lists */
    private static function createdIds(string $body): array
    {
        $data = json_decode($body, true);
        $list = \is_array($data) && array_is_list($data) ? $data : (\is_array($data['data'] ?? null) ? $data['data'] : []);
        $ids = [];
        foreach ($list as $item) {
            if (\is_array($item) && isset($item['purchase_reference'])) {
                $ids[(string) $item['purchase_reference']] = isset($item['id']) ? (string) $item['id'] : null;
            }
        }

        return $ids;
    }

    private static function errorDescription(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return null;
        }
        $found = null;
        array_walk_recursive($data, static function (mixed $value, mixed $key) use (&$found): void {
            if ($found === null && $key === 'error_description') {
                $found = \is_scalar($value) ? (string) $value : json_encode($value);
            }
        });

        return $found;
    }
}
