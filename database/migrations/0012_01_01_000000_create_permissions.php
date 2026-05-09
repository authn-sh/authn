<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('environment_id', 64);
            $table->string('key', 96);
            $table->string('name');
            $table->string('description', 512)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->foreign('environment_id')->references('id')->on('environments')->cascadeOnDelete();
            $table->unique(['environment_id', 'key']);
            $table->index(['environment_id', 'is_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
