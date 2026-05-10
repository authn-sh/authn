<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.4 social sign-in spine. Per-environment OauthProvider rows feed the
 * `oauth_<provider_key>` strategy family; ExternalAccount rows link a User
 * to a successful sign-in via one of those providers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_providers', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('provider_kind', 24);
            $table->string('provider_key', 64);
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_sign_in')->default(true);
            $table->boolean('allow_sign_up')->default(true);
            $table->boolean('block_email_subaddresses')->default(false);

            $table->string('client_id');
            $table->text('encrypted_client_secret');

            $table->jsonb('scopes')->default(json_encode([]));
            $table->jsonb('additional_authorization_params')->default(json_encode((object) []));
            $table->jsonb('attribute_mapping')->default(json_encode((object) []));

            $table->string('issuer')->nullable();
            $table->string('discovery_endpoint')->nullable();
            $table->timestamp('discovery_cached_at')->nullable();
            $table->string('authorization_endpoint')->nullable();
            $table->string('token_endpoint')->nullable();
            $table->string('userinfo_endpoint')->nullable();
            $table->string('jwks_uri')->nullable();
            $table->jsonb('id_token_signing_algs')->nullable();
            $table->string('userinfo_method', 8)->nullable();
            $table->string('userinfo_auth', 16)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'provider_key']);
        });

        Schema::create('external_accounts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->string('oauth_provider_id', 64);

            $table->string('provider_user_id');
            $table->string('email_address')->nullable();
            $table->boolean('verified')->default(false);
            $table->jsonb('scopes')->default(json_encode([]));
            $table->jsonb('public_metadata')->default(json_encode((object) []));

            $table->text('encrypted_access_token');
            $table->text('encrypted_refresh_token')->nullable();
            $table->text('encrypted_id_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();

            $table->timestamp('linked_at');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('oauth_provider_id')->references('id')->on('oauth_providers')->cascadeOnDelete();
            $table->unique(['environment_id', 'oauth_provider_id', 'provider_user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_accounts');
        Schema::dropIfExists('oauth_providers');
    }
};
