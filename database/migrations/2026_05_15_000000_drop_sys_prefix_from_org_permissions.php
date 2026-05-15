<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('key', 'like', 'org:sys\_%')
            ->orderBy('id')
            ->each(function (object $row): void {
                $next = preg_replace('/^org:sys_/', 'org:', (string) $row->key);
                if ($next !== null && $next !== $row->key) {
                    DB::table('permissions')->where('id', $row->id)->update(['key' => $next]);
                }
            });
    }

    public function down(): void {}
};
