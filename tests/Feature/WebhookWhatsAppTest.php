<?php

namespace Tests\Feature;

use App\Events\MessageStatusUpdated;
use App\Events\NewMessageReceived;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MetaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WebhookWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function metaSettingsRow(array $overrides = []): MetaSetting
    {
        return MetaSetting::create(array_merge([
            'phone_number_id' => 'phone_1',
            'waba_id' => 'waba_1',
            'app_id' => 'app_1',
            'verify_token' => 'verify_test_token',
            // SQLite schema after column changes may treat these as NOT NULL.
            'app_secret' => '',
            'access_token' => '',
        ], $overrides));
    }

    public function test_verify_challenge_with_meta_dot_query_keys(): void
    {
        $this->metaSettingsRow();

        // http_build_query() rewrites dotted keys; Meta sends hub.mode, hub.verify_token, hub.challenge literally.
        $response = $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verify_test_token&hub.challenge=999888');

        $response->assertOk();
        $this->assertSame('999888', $response->getContent());
    }

    public function test_verify_challenge_with_php_normalized_query_keys(): void
    {
        $this->metaSettingsRow();

        $response = $this->get('/api/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=verify_test_token&hub_challenge=abc');

        $response->assertOk();
        $this->assertSame('abc', $response->getContent());
    }

    public function test_verify_rejects_wrong_token(): void
    {
        $this->metaSettingsRow();

        $response = $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=x');

        $response->assertForbidden();
    }

    public function test_post_rejects_invalid_signature_when_app_secret_configured(): void
    {
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $body = '{"entry":[]}';
        $response = $this->call(
            'POST',
            '/api/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            ],
            $body
        );

        $response->assertForbidden();
        $response->assertJsonFragment(['error' => 'Invalid webhook signature']);
    }

    public function test_post_accepts_valid_signature_and_processes_text_message(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '+1'],
                        'messages' => [[
                            'from' => '15551234567',
                            'id' => 'wamid.UNITTEST',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Hello from test'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'top_secret');

        $response = $this->call(
            'POST',
            '/api/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
            ],
            $body
        );

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'processed']);

        $this->assertDatabaseHas('contacts', [
            'phone_number' => '15551234567',
            'created_via' => 'whatsapp_inbound',
        ]);
        $contact = Contact::where('phone_number', '15551234567')->first();
        $this->assertNotNull($contact);

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conversation);
        $conversation->refresh();
        $this->assertNotNull($conversation->window_expires_at);
        $this->assertTrue($conversation->window_expires_at->isFuture());
        $this->assertSame(1, (int) $conversation->unread_inbound_count);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'meta_message_id' => 'wamid.UNITTEST',
            'direction' => 'inbound',
            'sender_kind' => 'contact',
        ]);

        $msg = Message::where('meta_message_id', 'wamid.UNITTEST')->first();
        $this->assertSame('Hello from test', $msg->content);
        $this->assertNull($msg->sent_by_user_id);

        Event::assertDispatched(NewMessageReceived::class);
    }

    public function test_post_accepts_inbound_location_message(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '+1'],
                        'messages' => [[
                            'from' => '15557778888',
                            'id' => 'wamid.LOCATION1',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'location',
                            'location' => [
                                'latitude' => 24.7136,
                                'longitude' => 46.6753,
                                'name' => 'Riyadh pin',
                                'address' => 'Sample address',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'top_secret');

        $this->call(
            'POST',
            '/api/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
            ],
            $body
        )->assertOk();

        $this->assertDatabaseHas('messages', [
            'meta_message_id' => 'wamid.LOCATION1',
            'direction' => 'inbound',
            'type' => 'location',
            'sender_kind' => 'contact',
        ]);

        $msg = Message::where('meta_message_id', 'wamid.LOCATION1')->first();
        $this->assertStringContainsString('Riyadh pin', (string) $msg->content);
        $this->assertIsArray($msg->interactive_payload);
        $this->assertSame('location', $msg->interactive_payload['type'] ?? null);
    }

    public function test_post_duplicate_message_id_is_idempotent(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '+1'],
                        'messages' => [[
                            'from' => '15550001111',
                            'id' => 'wamid.DEDUPTEST',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Once'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'top_secret');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ];

        $this->call('POST', '/api/webhook/whatsapp', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/webhook/whatsapp', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(1, Message::query()->where('meta_message_id', 'wamid.DEDUPTEST')->count());
        Event::assertDispatchedTimes(NewMessageReceived::class, 1);

        $contact = Contact::where('phone_number', '15550001111')->first();
        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $conversation->refresh();
        $this->assertSame(1, (int) $conversation->unread_inbound_count);
    }

    public function test_status_update_is_idempotent_for_repeat_read(): void
    {
        Event::fake([MessageStatusUpdated::class]);
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $contact = Contact::create(['phone_number' => '15550002222']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => now()->addHour(),
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => 'wamid.STATUSDUP',
            'direction' => 'outbound',
            'sender_kind' => 'system',
            'type' => 'text',
            'content' => 'Hi',
            'status' => 'sent',
            'sent_at' => now(),
            'read_at' => now(),
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.STATUSDUP',
                            'status' => 'read',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'top_secret');
        $this->call(
            'POST',
            '/api/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
            ],
            $body
        )->assertOk();

        Event::assertNotDispatched(MessageStatusUpdated::class);
    }

    public function test_post_syncs_profile_name_from_contacts_payload(): void
    {
        Event::fake([NewMessageReceived::class]);
        $this->metaSettingsRow(['app_secret' => 'top_secret']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '+1'],
                        'contacts' => [[
                            'profile' => ['name' => 'Jane Customer'],
                            'wa_id' => '15559876543',
                        ]],
                        'messages' => [[
                            'from' => '15559876543',
                            'id' => 'wamid.PROFILETEST',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Hi'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'top_secret');

        $response = $this->call(
            'POST',
            '/api/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
            ],
            $body
        );

        $response->assertOk();

        $contact = Contact::where('phone_number', '15559876543')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Jane Customer', $contact->profile_name);
        $this->assertSame('Jane Customer', $contact->name);
    }
}
