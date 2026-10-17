<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\JsonTransport;

class CircleStudentWallets
{
    public function __construct(private readonly JsonTransport $http) {}

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    public function request(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $key = config('student-wallets.circle_api_key');
        if (! is_string($key) || ! str_starts_with($key, 'TEST_API_KEY:') || ! config('student-wallets.circle_app_id')) {
            throw ValidationException::withMessages(['circle' => 'Configure a Circle User-Controlled Wallets testnet API key and App ID.']);
        }
        $headers = ['Authorization' => 'Bearer '.$key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['X-User-Token'] = $token;
        }
        $response = $this->http->request($method, 'https://api.circle.com/v1/w3s/'.$path, $headers, $body);
        if ($path === 'users' && ($response->status === 409 || ($response->data['code'] ?? null) === 155101)) {
            return [];
        }
        if ($response->status < 200 || $response->status >= 300 || ! is_array($response->data['data'] ?? null)) {
            throw ValidationException::withMessages(['circle' => 'Circle request could not complete (HTTP '.$response->status.'). Reconnect your wallet session; do not resend an unresolved payment.']);
        }

        return $response->data['data'];
    }

    public function ensureUser(string $userId): void
    {
        $this->request('POST', 'users', ['userId' => $userId]);
    }

    /** @return array<string, mixed> */
    public function session(string $userId): array
    {
        return $this->request('POST', 'users/token', ['userId' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function wallets(string $token): array
    {
        $wallets = [];
        $after = null;
        do {
            $query = ['blockchain' => 'ARC-TESTNET', 'pageSize' => 50];
            if ($after !== null) {
                $query['pageAfter'] = $after;
            }
            $data = $this->request('GET', 'wallets?'.http_build_query($query), token: $token);
            $page = $data['wallets'] ?? [];
            foreach ($page as $wallet) {
                if (($wallet['blockchain'] ?? null) === 'ARC-TESTNET' && ($wallet['custodyType'] ?? null) === 'ENDUSER') {
                    $wallets[] = $wallet;
                }
            }
            $after = $data['nextPageAfter'] ?? null;
        } while (is_string($after) && $after !== '');

        return $wallets;
    }
}
