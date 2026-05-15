<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Dashboard\Concerns\ResolvesDashboardEnv;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class TemplatesController
{
    use ResolvesDashboardEnv;

    public function templates(string $project_slug, string $env_slug, ?string $tab = null): InertiaResponse|RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }
        $tab = in_array($tab, ['email', 'sms'], true) ? $tab : 'email';

        if ($tab === 'sms') {
            return Inertia::render('Dashboard/Configure/Templates/Sms', [
                'templates' => SmsTemplate::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->orderBy('slug')
                    ->get(['id', 'slug', 'body', 'delivered_by_us', 'from_number_override'])
                    ->map(fn (SmsTemplate $t) => [
                        'id' => $t->id,
                        'slug' => $t->slug,
                        'body' => $t->body,
                        'delivered_by_us' => (bool) $t->delivered_by_us,
                        'from_number_override' => $t->from_number_override,
                    ])->all(),
            ]);
        }

        return Inertia::render('Dashboard/Configure/Templates/Email', [
            'templates' => EmailTemplate::query()->withoutGlobalScopes()
                ->where('environment_id', $env->id)
                ->orderBy('slug')
                ->get(['id', 'slug', 'subject', 'delivered_by_us', 'updated_at'])
                ->map(fn (EmailTemplate $t) => [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'subject' => $t->subject,
                    'delivered_by_us' => (bool) $t->delivered_by_us,
                    'updated_at' => $t->updated_at?->getTimestampMs(),
                ])->all(),
        ]);
    }

    public function updateSmsTemplate(Request $request, string $project_slug, string $env_slug, string $slug): RedirectResponse
    {
        $env = $this->env($project_slug, $env_slug);
        if ($env === null) {
            return redirect(Url::dashboardPathPrefix().'/create-project');
        }

        $request->validate([
            'body' => ['nullable', 'string', 'max:1600'],
            'delivered_by_us' => ['nullable', 'boolean'],
            'from_number_override' => ['nullable', 'string', 'max:32'],
        ]);

        $row = SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('slug', $slug)
            ->first();
        if ($row === null) {
            return redirect()->back()->withErrors(['slug' => 'Template not found.']);
        }

        $patch = array_filter([
            'body' => $request->input('body'),
            'delivered_by_us' => $request->has('delivered_by_us') ? $request->boolean('delivered_by_us') : null,
            'from_number_override' => $request->input('from_number_override'),
        ], static fn ($v) => $v !== null);
        $row->forceFill($patch)->save();

        return redirect(Url::dashboardPathPrefix()."/{$project_slug}/{$env_slug}/configure/sms")
            ->with('sms_template_saved', true);
    }
}
