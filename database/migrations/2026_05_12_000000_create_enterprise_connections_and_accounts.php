<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.6 Enterprise SSO spine. EnterpriseConnection rows model one IdP per
 * (SAML or OIDC) — instance-wide when `organization_id` is null, per-org
 * otherwise. EnterpriseAccount rows link a User to a successful sign-in
 * via one of those connections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_connections', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('protocol', 8);
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->string('organization_id', 64)->nullable();

            $table->jsonb('domains')->default(json_encode([]));
            $table->string('default_role')->nullable();
            $table->jsonb('attribute_mapping')->default(json_encode((object) []));

            $table->string('saml_idp_entity_id')->nullable();
            $table->string('saml_sso_url')->nullable();
            $table->text('saml_idp_certificate')->nullable();
            $table->string('saml_signing_algorithm', 64)->nullable();
            $table->string('saml_audience_uri')->nullable();
            $table->text('saml_signing_key')->nullable();

            $table->string('oidc_issuer')->nullable();
            $table->string('oidc_discovery_endpoint')->nullable();
            $table->string('oidc_client_id')->nullable();
            $table->text('oidc_client_secret')->nullable();
            $table->jsonb('oidc_scopes')->default(json_encode([]));

            $table->timestamps();
            $table->softDeletes('removed_at');

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'enabled']);
            $table->index(['environment_id', 'protocol']);
        });

        Schema::create('enterprise_accounts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('user_id', 64);
            $table->string('enterprise_connection_id', 64);

            $table->string('provider_user_id');
            $table->string('email_address')->nullable();
            $table->boolean('verified')->default(false);
            $table->jsonb('public_metadata')->default(json_encode((object) []));
            $table->text('id_token')->nullable();

            $table->timestamp('linked_at');
            $table->timestamp('last_signed_in_at')->nullable();
            $table->timestamps();
            $table->softDeletes('removed_at');

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('enterprise_connection_id')->references('id')->on('enterprise_connections')->cascadeOnDelete();
            $table->unique(['enterprise_connection_id', 'provider_user_id']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_accounts');
        Schema::dropIfExists('enterprise_connections');
    }
};
