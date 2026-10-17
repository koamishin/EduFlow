<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

test('production redirects HTTP requests to HTTPS preserving path query and method', function (string $method): void {
    app()->instance('env', 'production');

    $this->call($method, 'http://localhost/https-check?filter=unpaid')
        ->assertStatus(308)
        ->assertRedirect('https://localhost/https-check?filter=unpaid');
})->with(['GET', 'POST']);

test('production accepts secure requests without redirect loops', function (): void {
    Route::get('/https-check', fn (): string => 'secure');
    app()->instance('env', 'production');

    $this->get('https://localhost/https-check')->assertOk()->assertSee('secure');
});

test('untrusted forwarded HTTPS headers cannot bypass production enforcement', function (): void {
    app()->instance('env', 'production');

    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/https-check')
        ->assertStatus(308)
        ->assertRedirect('https://localhost/https-check');
});

test('trusted TLS proxy requests avoid HTTPS redirect loops', function (): void {
    Route::get('/https-check', fn (): string => 'secure proxy');
    config(['trustedproxy.proxies' => ['192.0.2.10']]);
    app()->instance('env', 'production');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://localhost/https-check')
        ->assertOk()
        ->assertSee('secure proxy');
});

test('local environment continues to accept HTTP requests', function (): void {
    Route::get('/https-check', fn (): string => 'local');
    app()->instance('env', 'local');

    $this->get('http://localhost/https-check')->assertOk()->assertSee('local');
});

test('production defaults force secure URLs and session cookies', function (string $environment, bool $secure): void {
    app()->instance('env', $environment);
    config(['session.secure' => false]);
    URL::forceHttps(false);

    $provider = new class(app()) extends AppServiceProvider
    {
        public function applyDefaults(): void
        {
            $this->configureDefaults();
        }
    };
    $provider->applyDefaults();

    expect(url('/https-check'))->toStartWith($secure ? 'https://' : 'http://')
        ->and(config('session.secure'))->toBe($secure);
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
]);
