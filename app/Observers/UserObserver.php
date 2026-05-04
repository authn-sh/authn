<?php

declare(strict_types=1);

namespace App\Observers;

use App\Http\Resources\UserResource;
use App\Models\Environment;
use App\Models\User;
use App\Webhooks\Emitter;

/**
 * Fires `user.created`, `user.updated`, and `user.deleted` webhook events
 * via the Emitter. Resource shape is the BAPI flavour (includes
 * private_metadata) so consumers don't have to do a follow-up lookup.
 */
final class UserObserver
{
    public function __construct(private readonly Emitter $emitter) {}

    public function created(User $user): void
    {
        $this->emit('user.created', $user);
    }

    public function updated(User $user): void
    {
        $this->emit('user.updated', $user);
    }

    public function deleted(User $user): void
    {
        $this->emit('user.deleted', $user);
    }

    private function emit(string $type, User $user): void
    {
        $env = Environment::query()->withoutGlobalScopes()->where('id', $user->environment_id)->first();
        if ($env === null) {
            return;
        }
        $this->emitter->emit($type, UserResource::from($user, includePrivate: true), $env);
    }
}
