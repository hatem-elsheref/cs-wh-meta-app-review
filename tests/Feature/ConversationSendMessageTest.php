<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ConversationSendMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function actingUser(): User
    {
        $user = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_free_text_send_when_window_open_calls_meta_and_creates_message(): void
    {
        $user = $this->actingUser();

        $this->instance(MetaWhatsAppService::class, Mockery::mock(MetaWhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->once()->with('966500000000', 'Hello agent')->andReturn([
                'success' => true,
                'meta_message_id' => 'out_mid_1',
            ]);
        }));

        $contact = Contact::create(['phone_number' => '966500000000']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => now()->addHours(12),
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->id}/send", [
            'message' => 'Hello agent',
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['message' => 'Message sent successfully']);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'meta_message_id' => 'out_mid_1',
            'direction' => 'outbound',
            'sender_kind' => 'agent',
            'sent_by_user_id' => $user->id,
            'content' => 'Hello agent',
        ]);
    }

    public function test_free_text_rejected_when_window_closed(): void
    {
        $this->actingUser();

        $contact = Contact::create(['phone_number' => '966500000001']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => null,
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->id}/send", [
            'message' => 'Should not work',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['template_name'], 'details');
    }

    public function test_interactive_list_send_validates_sections(): void
    {
        $this->actingUser();

        $this->instance(MetaWhatsAppService::class, Mockery::mock(MetaWhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendInteractive')->once()->andReturn([
                'success' => true,
                'meta_message_id' => 'int_1',
            ]);
        }));

        $contact = Contact::create(['phone_number' => '966500000002']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->id}/send", [
            'type' => 'interactive_list',
            'body' => 'Pick one',
            'button_label' => 'Open',
            'sections' => [
                [
                    'title' => 'Options',
                    'rows' => [
                        ['id' => 'r1', 'title' => 'One', 'description' => 'First'],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }
}
