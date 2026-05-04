<?php

use App\Models\EmailTemplate;
use App\Support\Id;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates table (PLAN §11.4) plus a one-off seed for every existing
 * env so AU-9 / AU-10 sign-in / sign-up flows have a `verification_code`
 * row to render against. New envs get the same set via
 * `authn:bootstrap` (AU-2 already runs that command on first boot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('slug', 64);
            $table->string('subject');
            $table->string('from_email_name')->nullable();
            $table->string('reply_to_email_name')->nullable();
            $table->text('body_markup');                    // MJML source
            $table->text('body_html')->nullable();          // Compiled HTML cache
            $table->boolean('delivered_by_us')->default(true);
            $table->string('last_modified_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('last_modified_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['environment_id', 'slug']);
        });

        // Backfill: seed the v0.1 active set for every env that already exists.
        $envIds = DB::table('environments')->pluck('id');
        $now = now();
        foreach ($envIds as $envId) {
            foreach (EmailTemplate::DEFAULT_TEMPLATES as $slug => $payload) {
                $exists = DB::table('email_templates')
                    ->where('environment_id', $envId)
                    ->where('slug', $slug)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('email_templates')->insert([
                    'id' => Id::generate('tmpl_'),
                    'environment_id' => $envId,
                    'slug' => $slug,
                    'subject' => $payload['subject'],
                    'body_markup' => $payload['body_markup'],
                    'body_html' => $payload['body_html'],
                    'delivered_by_us' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
