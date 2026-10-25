<?php

declare(strict_types=1);

namespace Altmenorg\SyliusSkeepersPlugin\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth2 client credentials token of the Skeepers API account, one per audience.
 *
 * The token is kept in memory until 30 seconds before it expires (Skeepers gives 300 seconds).
 * Scope "openid" is required ("read" is refused). The verified-reviews API also needs the
 * audience "verified-reviews"; the purchase-event API needs none.
 */
final class TokenProvider
{
    private const MARGIN = 30;

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $tokenUrl,
        private readonly int $timeout = 30,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function token(?string $audience = null, bool $refresh = false): string
    {
        $key = $audience ?? '';
        $now = ($this->clock)();
        if (!$refresh && isset($this->tokens[$key]) && $this->tokens[$key]['expires'] - self::MARGIN > $now) {
            return $this->tokens[$key]['token'];
        }
        if ($this->clientId === null || $this->clientId === '' || $this->clientSecret === null || $this->clientSecret === '') {
            throw new SkeepersException('Skeepers API credentials are not configured (client_id, client_secret).');
        }

        $body = ['grant_type' => 'client_credentials', 'scope' => 'openid'];
        if ($audience !== null && $audience !== '') {
            $body['audience'] = $audience;
        }
        $response = $this->http->request('POST', $this->tokenUrl, [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)],
            'body' => $body,
            'timeout' => $this->timeout,
        ]);
        $status = $response->getStatusCode();
        $data = $status === 200 ? $response->toArray(false) : [];
        if (!isset($data['access_token']) || !\is_string($data['access_token'])) {
            throw new SkeepersException(sprintf('Skeepers token request failed (HTTP %d).', $status));
        }
        $this->tokens[$key] = [
            'token' => $data['access_token'],
            'expires' => $now + (int) ($data['expires_in'] ?? 300),
        ];

        return $data['access_token'];
    }
}
