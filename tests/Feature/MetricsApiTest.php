<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetricsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAgent(): User
    {
        $user = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_returns_aggregated_message_and_webhook_counts(): void
    {
        $this->actingAgent();

        $contact = Contact::create(['phone_number' => '966511111111']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => now()->addHour(),
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => 'm.in.1',
            'direction' => 'inbound',
            'sender_kind' => 'contact',
            'type' => 'text',
            'content' => 'Hi',
            'status' => 'received',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => 'm.out.1',
            'direction' => 'outbound',
            'sender_kind' => 'agent',
            'type' => 'text',
            'content' => 'Hello',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'meta_message_id' => 'm.out.2',
            'direction' => 'outbound',
            'sender_kind' => 'system',
            'type' => 'template',
            'content' => 'T',
            'template_name' => 't1',
            'status' => 'failed',
        ]);

        WebhookLog::create([
            'event_type' => 'message_received',
            'direction' => 'inbound',
        ]);

        WebhookLog::create([
            'event_type' => 'message_status',
            'direction' => 'outbound',
        ]);

        $response = $this->getJson('/api/metrics?period=today');

        $response->assertOk();
        $response->assertJsonPath('messages.inbound_total', 1);
        $response->assertJsonPath('messages.outbound_total', 2);
        $response->assertJsonPath('messages.outbound_template_total', 1);
        $response->assertJsonPath('messages.outbound_text_total', 1);
        $response->assertJsonPath('messages.outbound_status.failed', 1);
        $response->assertJsonPath('webhooks.incoming_total', 1);
        $response->assertJsonPath('webhooks.status_updates_total', 1);
        $response->assertJsonPath('webhooks.received_messages_total', 1);
    }
}
