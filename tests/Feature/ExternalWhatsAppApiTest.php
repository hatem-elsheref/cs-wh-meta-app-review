<?php

namespace Tests\Feature;

use App\Models\MetaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalWhatsAppApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_api_key(): void
    {
        config(['services.external_whatsapp.api_key' => 'configured-secret']);

        $response = $this->postJson('/api/external/whatsapp/templates/send', [
            'phone_number' => '966500000000',
            'template' => 'hello_world',
        ]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['code' => 'INVALID_API_KEY']);
    }

    public function test_returns_503_when_external_api_not_configured(): void
    {
        config(['services.external_whatsapp.api_key' => '']);

        $response = $this->postJson(
            '/api/external/whatsapp/templates/send',
            ['phone_number' => '966500000000', 'template' => 'hello_world'],
            ['X-API-Key' => 'ignored']
        );

        $response->assertStatus(503);
        $response->assertJsonFragment(['code' => 'EXTERNAL_API_DISABLED']);
    }

    public function test_sends_template_with_valid_api_key(): void
    {
        config(['services.external_whatsapp.api_key' => 'integration-key']);

        MetaSetting::create([
            'phone_number_id' => 'phone_1',
            'waba_id' => 'waba_1',
            'app_id' => 'app_1',
            'app_secret' => '',
            'access_token' => 'token_1',
            'verify_token' => 'v',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.ext.1']],
            ], 200),
        ]);

        $response = $this->postJson(
            '/api/external/whatsapp/templates/send',
            [
                'phone_number' => '966500000000',
                'template' => 'hello_world',
            ],
            ['X-API-Key' => 'integration-key']
        );

        $response->assertOk();
        $response->assertJsonPath('status', true);
        $this->assertDatabaseHas('messages', [
            'meta_message_id' => 'wamid.ext.1',
            'direction' => 'outbound',
            'type' => 'template',
            'sender_kind' => 'integration',
            'sent_by_user_id' => null,
        ]);
        $this->assertDatabaseHas('contacts', [
            'phone_number' => '966500000000',
            'created_via' => 'integration',
        ]);
    }
}
