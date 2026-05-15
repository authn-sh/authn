<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('environments')
            ->orderBy('id')
            ->each(function (object $env): void {
                $settings = $this->decode($env->user_settings);
                $attributes = is_array($settings['attributes'] ?? null) ? $settings['attributes'] : [];
                if (! array_key_exists('phone_number', $attributes)) {
                    return;
                }
                $value = (string) $attributes['phone_number'];
                $enabled = $value !== 'off';
                $required = $value === 'required';

                $signUpMethods = is_array($settings['sign_up_methods'] ?? null) ? $settings['sign_up_methods'] : [];
                $signUpMethods['phone'] = [
                    'enabled' => $enabled,
                    'required' => $required,
                ];
                $settings['sign_up_methods'] = $signUpMethods;

                unset($attributes['phone_number']);
                if ($attributes === []) {
                    unset($settings['attributes']);
                } else {
                    $settings['attributes'] = $attributes;
                }

                DB::table('environments')
                    ->where('id', $env->id)
                    ->update(['user_settings' => json_encode($settings)]);
            });
    }

    public function down(): void {}

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
