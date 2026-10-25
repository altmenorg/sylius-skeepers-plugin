<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Unit;

use Altmenorg\SyliusSkeepersPlugin\Client\TokenProvider;
use Altmenorg\SyliusSkeepersPlugin\PurchaseEvent\PurchaseEventSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PurchaseEventSenderTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /**
     * @param list<callable(list<array<string, mixed>>): MockResponse> $answers one per API request, in order
     */
    private function sender(array $answers, int $batchSize = 50): PurchaseEventSender
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $body = (string) ($options['body'] ?? '');
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if (str_contains($url, '/token')) {
                return new MockResponse(json_encode(['access_token' => 'T' . \count($this->requests), 'expires_in' => 300]));
            }
            $answer = array_shift($answers);
            self::assertNotNull($answer, 'unexpected API request');

            return $answer(json_decode($body, true));
        });
        $tokens = new TokenProvider($http, 'id', 'secret', 'https://auth.example/token');

        return new PurchaseEventSender($http, $tokens, 'https://api.example', 'W1', null, $batchSize);
    }

    /** @return array<int, array<string, mixed>> */
    private static function events(int $n): array
    {
        $events = [];
        for ($i = 1; $i <= $n; ++$i) {
            $events[1000 + $i] = ['purchase_reference' => (string) (230000 + $i)];
        }

        return $events;
    }

    private static function created(array $events, int $status = 201): MockResponse
    {
        return new MockResponse(json_encode(array_map(static fn (array $e): array => $e + ['id' => 'id-' . $e['purchase_reference'], 'purchase_event_status' => 'CREATED'], $events)), ['http_code' => $status]);
    }

    private function apiRequests(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => !str_contains($r['url'], '/token')));
    }

    public function testBatchesOfFiftyAtMost(): void
    {
        $batches = PurchaseEventSender::batches(self::events(120));
        self::assertSame([50, 50, 20], array_map('count', $batches));
        self::assertSame(1001, $batches[0][0]);
        self::assertSame(1120, $batches[2][19]);
        self::assertSame([50, 50, 20], array_map('count', PurchaseEventSender::batches(self::events(120), 500)), 'never more than 50');
        self::assertSame([10, 10, 10], array_map('count', PurchaseEventSender::batches(self::events(30), 10)));
    }

    public function testAllSentIn201AndSplitBy50(): void
    {
        $answer = static fn (array $events): MockResponse => self::created($events);
        $results = $this->sender([$answer, $answer, $answer])->send(self::events(120));

        $api = $this->apiRequests();
        self::assertCount(3, $api);
        self::assertSame('https://api.example/purchase-event/websites/W1/purchase_events/bulk_sync_insert', $api[0]['url']);
        self::assertSame([50, 50, 20], array_map(static fn (array $r): int => \count(json_decode($r['body'], true)), $api));
        self::assertTrue(array_is_list(json_decode($api[0]['body'], true)), 'the body is a JSON array');
        self::assertCount(120, $results);
        self::assertSame('sent', $results[1077]['state']);
        self::assertSame('id-230077', $results[1077]['skeepers_id']);
        // one token for the three requests
        self::assertCount(1, array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], '/token')));
    }

    public function testPartialContentKeepsTheCreatedOnes(): void
    {
        $answer = static fn (array $events): MockResponse => new MockResponse(json_encode([
            ['purchase_reference' => '230001', 'id' => 'id-1'],
            ['error_description' => 'consumer.email is invalid'],
        ]), ['http_code' => 206]);
        $results = $this->sender([$answer])->send(self::events(2));

        self::assertSame('sent', $results[1001]['state']);
        self::assertSame('error', $results[1002]['state']);
        self::assertStringContainsString('consumer.email is invalid', $results[1002]['response']);
    }

    public function testBadRequestIsSentAgainOneByOne(): void
    {
        $bad = static fn (array $events): MockResponse => new MockResponse('{"error":{"message":"bad"}}', ['http_code' => 400]);
        $ok = static fn (array $events): MockResponse => self::created($events);
        $results = $this->sender([$bad, $ok, $bad, $ok])->send(self::events(3));

        self::assertSame(['sent', 'error', 'sent'], array_column(array_values($results), 'state'));
        self::assertSame([3, 1, 1, 1], array_map(static fn (array $r): int => \count(json_decode($r['body'], true)), $this->apiRequests()));
    }

    public function testRateLimitStopsAndKeepsPending(): void
    {
        $ok = static fn (array $events): MockResponse => self::created($events);
        $limited = static fn (array $events): MockResponse => new MockResponse('', ['http_code' => 429]);
        $results = $this->sender([$ok, $limited], 2)->send(self::events(6));

        self::assertSame(['sent', 'sent', 'deferred', 'deferred'], array_column(array_values($results), 'state'));
        self::assertArrayNotHasKey(1005, $results, 'not tried after the rate limit');
    }

    public function testServerErrorIsAnUnknownOutcomeAndStops(): void
    {
        $down = static fn (array $events): MockResponse => new MockResponse('', ['http_code' => 503]);
        $results = $this->sender([$down], 2)->send(self::events(4));

        self::assertSame(['error', 'error'], array_column(array_values($results), 'state'));
        self::assertStringContainsString('outcome unknown', $results[1001]['response']);
        self::assertCount(2, $results);
    }

    public function testExpiredTokenIsRenewedOnce(): void
    {
        $denied = static fn (array $events): MockResponse => new MockResponse('', ['http_code' => 401]);
        $ok = static fn (array $events): MockResponse => self::created($events);
        $results = $this->sender([$denied, $ok])->send(self::events(1));

        self::assertSame('sent', $results[1001]['state']);
        self::assertCount(2, array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], '/token')));
    }
}
