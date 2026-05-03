<?php

declare(strict_types=1);

namespace App\Auth\Strategies;

use App\Models\SignInAttempt;

/**
 * Each first-factor strategy implements this. The controller routes
 * `prepare_first_factor` and `attempt_first_factor` calls to the right
 * implementation via App\Auth\StrategyResolver.
 */
interface Strategy
{
    public function name(): string;

    /**
     * Long-form description of whether this strategy needs a prepare
     * step. password / ticket are one-shot — they answer false here
     * and the controller returns prepare_not_required.
     */
    public function requiresPrepare(): bool;

    /**
     * Issue any out-of-band material (email code, magic link). Called
     * by `POST /v1/client/sign_ins/{id}/prepare_first_factor`.
     *
     * @param  array<string, mixed>  $params
     */
    public function prepare(SignInAttempt $attempt, array $params): StrategyResult;

    /**
     * Validate the user's response. Called by `attempt_first_factor`.
     * Updates `attempt->status` per the lifecycle (PLAN §9.1):
     *   correct → complete (or needs_new_password for reset)
     *   wrong   → still in needs_first_factor; verification.attempts incremented
     *
     * @param  array<string, mixed>  $params
     */
    public function attempt(SignInAttempt $attempt, array $params): StrategyResult;
}
