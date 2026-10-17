<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Settings\InstallationSettings;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\LaravelSettings\Models\SettingsProperty;

#[Scoped]
class InstallationInstitution
{
    private ?Organization $explicitOrganization = null;

    public function setExplicitOrganization(?Organization $organization): void
    {
        $this->explicitOrganization = $organization;
    }

    public function current(?User $forUser = null): ?Organization
    {
        if ($this->explicitOrganization instanceof Organization) {
            return $this->explicitOrganization;
        }

        // 0. Explicit configuration lock (e.g. tests or dedicated single-institution environment)
        $configuredId = config('eduflow.institution_id');
        if ($configuredId !== null && $configuredId !== '') {
            if (filter_var($configuredId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                $configuredOrg = Organization::query()->find((int) $configuredId);
                if ($configuredOrg instanceof Organization) {
                    return $configuredOrg;
                }
            }

            return null;
        }

        // 1. Route parameters (institution_id or institution) or query params
        if (app()->bound('request')) {
            /** @var Request|null $request */
            $request = request();
            if ($request instanceof Request) {
                $param = $request->route('institution_id')
                    ?? $request->route('institution')
                    ?? $request->query('institution_id')
                    ?? $request->query('institution');

                if ($param instanceof Organization) {
                    return $param;
                }

                if (is_numeric($param) && (int) $param > 0) {
                    $org = Organization::query()->find((int) $param);
                    if ($org instanceof Organization) {
                        return $org;
                    }
                } elseif (is_string($param) && $param !== '') {
                    $org = Organization::query()
                        ->where('slug', $param)
                        ->orWhere('domain', $param)
                        ->first();
                    if ($org instanceof Organization) {
                        return $org;
                    }
                }
            }
        }

        // 2. Authenticated user's organization or session current_organization_id
        if (app()->bound('session')) {
            $sessionId = session('current_organization_id');
            if (is_numeric($sessionId) && (int) $sessionId > 0) {
                $org = Organization::query()->find((int) $sessionId);
                if ($org instanceof Organization) {
                    return $org;
                }
            }
        }

        $user = $forUser;
        if (! $user && Auth::check()) {
            $user = Auth::user();
        }
        if (! $user && app()->bound('request')) {
            $user = request()?->user();
        }

        if ($user instanceof User) {
            if (! empty($user->organization_id)) {
                $org = Organization::query()->find((int) $user->organization_id);
                if ($org instanceof Organization) {
                    return $org;
                }
            }

            if ($user->student && ! empty($user->student->organization_id)) {
                $org = Organization::query()->find((int) $user->student->organization_id);
                if ($org instanceof Organization) {
                    return $org;
                }
            }
        }

        // 3. Stored institution from settings
        if (Schema::hasTable((new SettingsProperty)->getTable())) {
            $payload = SettingsProperty::query()->where('group', InstallationSettings::group())
                ->where('name', 'institution_id')->value('payload');
            $decoded = $payload === null ? null : json_decode((string) $payload, true);
            if (is_int($decoded) && $decoded > 0) {
                $org = Organization::query()->find($decoded);
                if ($org instanceof Organization) {
                    return $org;
                }
            }
        }

        // 4. Single-institution installation fallback / CLI & queue fallback
        $institutions = Organization::query()->limit(2)->get();
        if ($institutions->count() === 1) {
            return $institutions->first();
        }

        if (app()->runningInConsole() || app()->environment(['local', 'testing'])) {
            return Organization::query()->first();
        }

        return null;
    }

    public function require(?User $forUser = null): Organization
    {
        return $this->current($forUser) ?? throw new RuntimeException(
            'Institution context unavailable. Run app:install or configure an active institution.'
        );
    }
}
