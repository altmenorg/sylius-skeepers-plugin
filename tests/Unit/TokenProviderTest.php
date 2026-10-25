<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Tests\Unit;

use Altmenorg\SyliusSkeepersPlugin\Client\TokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenProviderTest extends TestCase
{
    public function testTokenIsKeptUntilThirtySecondsBeforeExpiry(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = $options;

            return new MockResponse(json_encode(['access_token' => 'T' . \count($calls), 'expires_in' => 300]));
        });
        $now = 1000;
        $tokens = new TokenProvider($http, 'id', 'secret', 'https://auth.example/token', 30, static function () use (&$now): int {
            return $now;
        });

        self::assertSame('T1', $tokens->token());
        $now = 1269;
        self::assertSame('T1', $tokens->token());
        $now = 1270;
        self::assertSame('T2', $tokens->token());
        self::assertSame('T3', $tokens->token('verified-reviews'), 'one token per audience');

        self::assertContains('Authorization: Basic ' . base64_encode('id:secret'), $calls[0]['headers']);
        self::assertSame('grant_type=client_credentials&scope=openid', $calls[0]['body']);
        self::assertSame('grant_type=client_credentials&scope=openid&audience=verified-reviews', $calls[2]['body']);
    }
}
