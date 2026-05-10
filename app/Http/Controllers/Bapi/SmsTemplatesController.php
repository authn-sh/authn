<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Models\SmsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET    /v1/sms-templates                — list active templates
 *   GET    /v1/sms-templates/{slug}         — read one
 *   PATCH  /v1/sms-templates/{slug}         — update body / overrides
 *   POST   /v1/sms-templates/{slug}/revert  — restore the seeded default
 *
 * Slug-keyed since `SmsTemplate.slug` is the canonical identifier the
 * send pipeline looks up. Per-env scoping comes from the bound
 * `Environment` instance.
 */
final class SmsTemplatesController
{
    public function index(): JsonResponse
    {
        $env = app(Environment::class);
        $rows = SmsTemplate::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SmsTemplate $r) => $this->shape($r))->all(),
            'total_count' => $rows->count(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $row = $this->find($slug);
        if ($row === null) {
            return $this->notFound($slug);
        }

        return response()->json($this->shape($row));
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $row = $this->find($slug);
        if ($row === null) {
            return $this->notFound($slug);
        }

        $request->validate([
            'body' => ['nullable', 'string', 'max:1600'],
            'delivered_by_us' => ['nullable', 'boolean'],
            'from_number_override' => ['nullable', 'string', 'max:32'],
        ]);

        $patch = array_filter([
            'body' => $request->input('body'),
            'delivered_by_us' => $request->has('delivered_by_us') ? $request->boolean('delivered_by_us') : null,
            'from_number_override' => $request->input('from_number_override'),
        ], static fn ($v) => $v !== null);

        $row->forceFill($patch)->save();

        return response()->json($this->shape($row->refresh()));
    }

    public function revert(string $slug): JsonResponse
    {
        $row = $this->find($slug);
        if ($row === null) {
            return $this->notFound($slug);
        }

        $defaults = SmsTemplate::DEFAULT_TEMPLATES[$slug] ?? null;
        if ($defaults === null) {
            return response()->json([
                'errors' => [['code' => 'sms_template_not_seeded', 'message' => 'No seeded default for this slug.', 'long_message' => 'No seeded default for this slug.', 'meta' => []]],
                'trace_id' => null,
            ], 422);
        }

        $row->forceFill([
            'body' => $defaults['body'],
            'delivered_by_us' => true,
            'from_number_override' => null,
        ])->save();

        return response()->json($this->shape($row->refresh()));
    }

    private function find(string $slug): ?SmsTemplate
    {
        $env = app(Environment::class);

        return SmsTemplate::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('slug', $slug)
            ->first();
    }

    private function notFound(string $slug): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => 'sms_template_not_found', 'message' => "Template '{$slug}' not found.", 'long_message' => "Template '{$slug}' not found.", 'meta' => []]],
            'trace_id' => null,
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(SmsTemplate $row): array
    {
        return [
            'object' => 'sms_template',
            'id' => $row->id,
            'slug' => $row->slug,
            'body' => $row->body,
            'delivered_by_us' => (bool) $row->delivered_by_us,
            'from_number_override' => $row->from_number_override,
            'created_at' => $row->created_at?->getTimestampMs(),
            'updated_at' => $row->updated_at?->getTimestampMs(),
        ];
    }
}
