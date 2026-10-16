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
    public function current(?User $forUser = null): ?Organization
    {
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

        // 3. Single-institution installation fallback / configured environment identity
        // In a multi-school database, static config alone cannot make requests safe; dynamic tenancy context is required.
        $institutions = Organization::query()->limit(2)->get();
        if ($institutions->count() !== 1) {
            return null;
        }

        $soleInstitution = $institutions->first();
        if (! $soleInstitution instanceof Organization) {
            return null;
        }

        $id = config('eduflow.institution_id');
        $configured = $id !== null && $id !== '';
        $storedId = null;

        if (Schema::hasTable((new SettingsProperty)->getTable())) {
            $payload = SettingsProperty::query()->where('group', InstallationSettings::group())
                ->where('name', 'institution_id')->value('payload');
            $storedId = $payload === null ? null : json_decode((string) $payload, true);

            if ($payload !== null && json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            if ($storedId !== null && (! is_int($storedId) || $storedId < 1)) {
                return null;
            }
        }

        if (! $configured && $storedId !== null) {
            $id = $storedId;
            $configured = true;
        }

        if ($configured) {
            if (! is_int($id) && ! is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return null;
            }

            if ($soleInstitution->id === (int) $id) {
                return $soleInstitution;
            }

            return null;
        }

        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        // 4. Fallback to primary organization for existing CLI / test suites in single-institution setup
        return $soleInstitution;
    }

    public function require(?User $forUser = null): Organization
    {
        return $this->current($forUser) ?? throw new RuntimeException(
            'Institution context unavailable. Run app:install or configure an active institution.'
        );
    }
}
