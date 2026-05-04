<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook subsystem tables (PLAN §14):
 *
 *   webhook_endpoints   per-env destination URLs + signing secret(s)
 *   webhook_events      one immutable row per Emitter::emit call
 *   webhook_deliveries  one row per (event, endpoint) pair tracking attempts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('url', 2048);
            $table->string('signing_secret');                       // raw 32-byte base64
            $table->string('prior_signing_secret')->nullable();
            $table->timestamp('prior_signing_secret_expires_at')->nullable();
            $table->jsonb('enabled_event_types')->default(json_encode(['*']));
            $table->boolean('enabled')->default(true);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['environment_id', 'enabled']);
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('type', 128);
            $table->jsonb('data');
            $table->boolean('was_test')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['environment_id', 'type', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('webhook_endpoint_id', 64);
            $table->string('webhook_event_id', 64);
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
            $table->foreign('webhook_event_id')->references('id')->on('webhook_events')->cascadeOnDelete();
            $table->index(['webhook_endpoint_id', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_endpoints');
    }
};
