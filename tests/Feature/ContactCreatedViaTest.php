<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactCreatedViaTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_from_ui_sets_created_via_manual(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contacts', [
            'phone_number' => '966599999999',
            'name' => 'Test',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('contacts', [
            'phone_number' => '966599999999',
            'created_via' => 'manual',
        ]);
    }
}
