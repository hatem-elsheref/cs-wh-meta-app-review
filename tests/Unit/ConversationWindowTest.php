<?php

namespace Tests\Unit;

use App\Models\Conversation;
use Tests\TestCase;

class ConversationWindowTest extends TestCase
{
    public function test_window_closed_when_expires_at_is_null(): void
    {
        $c = new Conversation([
            'contact_id' => 1,
            'window_expires_at' => null,
        ]);

        $this->assertFalse($c->isWindowOpen());
        $this->assertTrue($c->mustUseTemplate());
        $this->assertFalse($c->canSendFreeText());
    }

    public function test_window_open_when_expires_at_is_in_future(): void
    {
        $c = new Conversation([
            'contact_id' => 1,
            'window_expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($c->isWindowOpen());
        $this->assertFalse($c->mustUseTemplate());
        $this->assertTrue($c->canSendFreeText());
    }

    public function test_window_closed_when_expires_at_is_in_past(): void
    {
        $c = new Conversation([
            'contact_id' => 1,
            'window_expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse($c->isWindowOpen());
        $this->assertTrue($c->mustUseTemplate());
    }
}
