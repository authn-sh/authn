<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.7 Dashboard Customization editor: short-lived preview drafts.
 *
 * The dashboard appearance editor mints a token row via
 * POST /configure/appearance/preview and loads the resulting signed URL
 * inside a preview <iframe>; the Account Portal preview render route
 * looks the row up and swaps the env's stored appearance for the draft
 * for the duration of that one render. Rows expire after 5 minutes.
 * The row holder doubles as the bearer secret — `token` is high-entropy
 * and unguessable; lookups are env-scoped to defeat cross-env replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appearance_preview_drafts', function (Blueprint $table): void {
            $table->string('token', 64)->primary();
            $table->string('environment_id', 64);
            $table->jsonb('draft');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appearance_preview_drafts');
    }
};
