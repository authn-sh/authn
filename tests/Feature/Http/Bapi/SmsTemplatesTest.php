<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\Environment;
use App\Models\SmsTemplate;
use Tests\TestCase;

final class SmsTemplatesTest extends TestCase
{
    public function test_index_returns_the_three_seeded_templates(): void
    {
        $boot = BapiTestSupport::bootEnv('smslist');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->withoutOpenApiAssertions()
            ->getJson(BapiTestSupport::url('/sms-templates'), BapiTestSupport::headers($boot['token']));

        $resp->assertOk();
        $resp->assertJsonCount(3, 'data');
        $slugs = collect($resp->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['invitation', 'reset_password_code', 'verification_code'], $slugs);
    }

    public function test_show_returns_one_template(): void
    {
        $boot = BapiTestSupport::bootEnv('smsshow');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->withoutOpenApiAssertions()
            ->getJson(BapiTestSupport::url('/sms-templates/verification_code'), BapiTestSupport::headers($boot['token']));

        $resp->assertOk();
        $resp->assertJson([
            'object' => 'sms_template',
            'slug' => 'verification_code',
            'delivered_by_us' => true,
        ]);
    }

    public function test_show_404s_for_unseeded_slugs(): void
    {
        $boot = BapiTestSupport::bootEnv('smsmiss');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->withoutOpenApiAssertions()
            ->getJson(BapiTestSupport::url('/sms-templates/never_seeded'), BapiTestSupport::headers($boot['token']));

        $resp->assertNotFound();
        $this->assertSame('sms_template_not_found', $resp->json('errors.0.code'));
    }

    public function test_update_writes_through_body_and_delivered_by_us(): void
    {
        $boot = BapiTestSupport::bootEnv('smsedit');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->withoutOpenApiAssertions()->patchJson(
            BapiTestSupport::url('/sms-templates/verification_code'),
            ['body' => 'Custom body {{otp_code}}', 'delivered_by_us' => false],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson(['body' => 'Custom body {{otp_code}}', 'delivered_by_us' => false]);

        $row = SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $boot['env']->id)
            ->where('slug', 'verification_code')
            ->firstOrFail();
        $this->assertSame('Custom body {{otp_code}}', $row->body);
        $this->assertFalse((bool) $row->delivered_by_us);
    }

    public function test_revert_restores_the_seeded_default_body(): void
    {
        $boot = BapiTestSupport::bootEnv('smsrevert');
        app()->instance(Environment::class, $boot['env']);

        SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $boot['env']->id)
            ->where('slug', 'verification_code')
            ->update(['body' => 'edited', 'delivered_by_us' => false, 'from_number_override' => '+15550000000']);

        $resp = $this->withoutOpenApiAssertions()->postJson(
            BapiTestSupport::url('/sms-templates/verification_code/revert'),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $row = SmsTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $boot['env']->id)
            ->where('slug', 'verification_code')
            ->firstOrFail();
        $this->assertSame(SmsTemplate::DEFAULT_TEMPLATES['verification_code']['body'], $row->body);
        $this->assertTrue((bool) $row->delivered_by_us);
        $this->assertNull($row->from_number_override);
    }
}
