<?php

use App\Models\SmsTemplate;
use App\Support\Id;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-env SMS templates. Mirror of the v0.1 email-template table —
 * `slug` keyed, single `body` (Liquid placeholders, no MJML), and a
 * `from_number_override` for envs that want to send a specific template
 * from a different sender than the env-wide default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('slug', 64);
            $table->text('body');
            $table->boolean('delivered_by_us')->default(true);
            $table->string('from_number_override')->nullable();
            $table->string('last_modified_by_user_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->foreign('last_modified_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['environment_id', 'slug']);
        });

        $envIds = DB::table('environments')->pluck('id');
        $now = now();
        foreach ($envIds as $envId) {
            foreach (SmsTemplate::DEFAULT_TEMPLATES as $slug => $payload) {
                $exists = DB::table('sms_templates')
                    ->where('environment_id', $envId)
                    ->where('slug', $slug)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('sms_templates')->insert([
                    'id' => Id::generate('tmpl_'),
                    'environment_id' => $envId,
                    'slug' => $slug,
                    'body' => $payload['body'],
                    'delivered_by_us' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
