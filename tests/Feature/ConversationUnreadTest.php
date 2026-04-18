<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationUnreadTest extends TestCase
{
    use RefreshDatabase;

    private function actingAgent(): User
    {
        $user = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_mark_read_clears_unread_count(): void
    {
        $this->actingAgent();

        $contact = Contact::create(['phone_number' => '966522222222']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'status' => 'open',
            'window_expires_at' => now()->addHour(),
            'unread_inbound_count' => 3,
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->id}/mark-read");

        $response->assertOk();
        $response->assertJsonPath('data.unread_inbound_count', 0);

        $conversation->refresh();
        $this->assertSame(0, $conversation->unread_inbound_count);
        $this->assertNotNull($conversation->last_read_at);
    }
}
