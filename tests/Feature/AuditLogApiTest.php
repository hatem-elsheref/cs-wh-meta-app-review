<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MetaSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_meta_settings_save_writes_audit_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'approved']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/settings', [
            'phone_number_id' => 'p1',
            'waba_id' => 'w1',
            'app_id' => 'a1',
            'app_secret' => 'sec',
            'access_token' => 'tok',
            'verify_token' => 'v1',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'meta_settings.saved',
        ]);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_agent_cannot_list_audit_logs(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($agent);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_admin_can_list_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'approved']);
        Sanctum::actingAs($admin);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'test.action',
            'ip' => '127.0.0.1',
        ]);

        $response = $this->getJson('/api/audit-logs');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.action', 'test.action');
    }
}
