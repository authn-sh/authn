<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('oauth_providers')
            ->where('client_id', '')
            ->where('encrypted_client_secret', '')
            ->delete();
    }

    public function down(): void {}
};
