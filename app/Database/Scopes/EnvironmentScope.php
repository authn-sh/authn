<?php

declare(strict_types=1);

namespace App\Database\Scopes;

use App\Models\Environment;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters every query for the model by the Environment currently bound
 * into the container. Bind happens upstream:
 *   - FAPI requests: ResolveProjectFromHost (AU-3) binds on host match.
 *   - BAPI requests: AuthenticateBapiKey (AU-3) binds on key resolve.
 *   - Internal jobs: the dispatcher binds before invoking the handler.
 *
 * When no Environment is bound the scope is a no-op — the cross-cutting
 * background paths (queue workers picking up shared rows, the bootstrap
 * artisan command provisioning the very first env, the test suite's
 * model factories) need to query without one in flight. The strict
 * "throw when missing" mode from PLAN §17.5 lands as a separate config
 * flag in the BAPI middleware (AU-13) where it actually matters.
 *
 * To deliberately bypass on a single query, use:
 *   `User::withoutGlobalScope(EnvironmentScope::class)->...`
 */
final class EnvironmentScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $env = self::currentEnvironment();
        if ($env === null) {
            return;
        }

        $builder->where($model->qualifyColumn('environment_id'), $env->id);
    }

    public static function currentEnvironment(): ?Environment
    {
        $container = Container::getInstance();
        if (! $container->bound(Environment::class)) {
            return null;
        }

        $instance = $container->make(Environment::class);

        return $instance instanceof Environment ? $instance : null;
    }
}
