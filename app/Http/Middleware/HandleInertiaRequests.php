<?php

namespace App\Http\Middleware;

use App\Enums\SocialLoginProvider;
use App\Features\FeatureRegistry;
use App\Http\Controllers\DashboardController;
use App\Services\ArcNetworkGateway as AppArcNetworkGateway;
use App\Settings\ApplicationFeaturesSettings;
use App\Settings\SocialLoginSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    #[\Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        FeatureRegistry::initialize();

        $user = $request->user();
        $settingsFeatures = [];

        if ($user) {
            $settingsFeatures = [
                'profile' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile'),
                'security' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_app')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_email')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
                'password' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password'),
                'appearance' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_appearance'),
                'passkeys' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
            ];
        }

        $socialLoginSettings = app(SocialLoginSettings::class);

        $socialProviders = collect(SocialLoginProvider::cases())
            ->filter(fn (SocialLoginProvider $socialLoginProvider): bool => $socialLoginSettings->isProviderEnabled($socialLoginProvider))
            ->map(fn (SocialLoginProvider $socialLoginProvider): array => [
                'slug' => $socialLoginProvider->value,
                'label' => $socialLoginProvider->label(),
                'url' => route('auth.social.redirect', ['provider' => $socialLoginProvider->value]),
            ])
            ->values()
            ->all();

        $isMainnet = false;
        $chainCode = (string) config('lepton.arc.chain', 'ARC-TESTNET');
        $chainId = (int) config('lepton.arc.chain_id', 5042);

        try {
            $networkGateway = app(ArcNetworkGateway::class);
            $isMainnet = AppArcNetworkGateway::isMainnetGateway($networkGateway);
            $chainCode = $networkGateway->chainCode();
            $chainId = $networkGateway->chainId();
        } catch (\Throwable) {
            // Keep default config values when gateway is not fully configured or mocked without network expectations
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'network' => [
                'isMainnet' => $isMainnet,
                'name' => $isMainnet ? 'Arc Mainnet' : 'Arc Testnet',
                'chainCode' => $chainCode,
                'chainId' => $chainId,
                'explorerUrl' => AppArcNetworkGateway::normalizeExplorerUrl(
                    $isMainnet
                        ? (string) config('lepton.explorer.mainnet', 'https://explorer.arc.io')
                        : (string) config('lepton.explorer.testnet', 'https://testnet.arcscan.app')
                ),
            ],
            'auth' => [
                'user' => $request->user(),
                'impersonating' => app('impersonate')->isImpersonating(),
                // Students have no Filament panel, so navigation that only
                // exists for them (their academic record) branches on this.
                'isStudent' => $user !== null && $user->hasRole('student'),
            ],
            'notificationSummary' => $user !== null
                ? DashboardController::buildNotificationSummary($user)
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'authLayout' => app(ApplicationFeaturesSettings::class)->auth_layout,
            'settingsFeatures' => $settingsFeatures,
            'socialProviders' => $socialProviders,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'status' => $request->session()->get('status'),
            ],
        ];
    }
}
