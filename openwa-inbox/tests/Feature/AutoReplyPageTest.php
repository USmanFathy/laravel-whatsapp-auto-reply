<?php

namespace Tests\Feature;

use App\Models\AutoReplySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class AutoReplyPageTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_the_page_requires_the_admin_password(): void
    {
        $this->get('/auto-reply')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->withServerVariables(['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'wrong'])->get('/auto-reply')->assertStatus(401);
        $this->withServerVariables(['PHP_AUTH_USER' => 'other', 'PHP_AUTH_PW' => 'test-admin-pass'])->get('/auto-reply')->assertStatus(401);
    }

    public function test_nobody_can_change_the_message_without_the_password(): void
    {
        $this->enableAutoReply('Original');

        $this->put('/auto-reply', ['enabled' => '0', 'message' => 'Hacked'])->assertStatus(401);
        $this->post('/auto-reply/retry-failed')->assertStatus(401);

        $setting = AutoReplySetting::current();
        $this->assertTrue($setting->enabled);
        $this->assertSame('Original', $setting->message);
    }

    public function test_the_page_is_disabled_until_a_password_is_configured(): void
    {
        config(['openwa.admin.password' => '']);

        $this->get('/auto-reply')->assertStatus(503);
        $this->put('/auto-reply', ['enabled' => '1', 'message' => 'x'])->assertStatus(503);
        $this->assertNull(AutoReplySetting::current()->message);
    }

    public function test_saving_and_loading_the_global_reply(): void
    {
        $this->asAdmin()->put('/auto-reply', ['enabled' => '1', 'message' => "  Hello!\nWe reply soon.  "])
            ->assertRedirect(route('auto-reply'))->assertSessionHas('status')->assertSessionHasNoErrors();

        $setting = AutoReplySetting::current();
        $this->assertTrue($setting->enabled);
        $this->assertSame("Hello!\nWe reply soon.", $setting->message);

        $this->asAdmin()->get('/auto-reply')->assertOk()->assertSee('Hello!')->assertSee('We reply soon.')->assertSee('ON')->assertSee('checked', false);
    }

    public function test_enabling_and_disabling_keeps_the_message(): void
    {
        $this->enableAutoReply('Keep this text');

        $this->asAdmin()->put('/auto-reply', ['message' => 'Keep this text'])->assertSessionHasNoErrors(); // checkbox unchecked
        $setting = AutoReplySetting::current();
        $this->assertFalse($setting->enabled);
        $this->assertSame('Keep this text', $setting->message);

        $this->asAdmin()->get('/auto-reply')->assertOk()->assertSee('OFF');

        $this->asAdmin()->put('/auto-reply', ['enabled' => '1', 'message' => 'Keep this text']);
        $this->assertTrue(AutoReplySetting::current()->enabled);
    }

    public function test_enabling_with_an_empty_message_is_rejected_and_changes_nothing(): void
    {
        $this->asAdmin()->from('/auto-reply')->put('/auto-reply', ['enabled' => '1', 'message' => '   '])
            ->assertRedirect('/auto-reply')->assertSessionHasErrors('message');

        $this->assertFalse(AutoReplySetting::current()->enabled);
        $this->assertNull(AutoReplySetting::current()->message);
    }

    public function test_too_long_message_is_rejected(): void
    {
        $this->asAdmin()->put('/auto-reply', ['enabled' => '1', 'message' => str_repeat('a', 4097)])->assertSessionHasErrors('message');
    }

    public function test_an_empty_message_is_fine_while_switched_off(): void
    {
        $this->asAdmin()->put('/auto-reply', ['message' => ''])->assertSessionHasNoErrors();
        $this->assertFalse(AutoReplySetting::current()->enabled);
    }

    public function test_the_root_url_goes_to_the_configuration_page(): void
    {
        $this->asAdmin()->get('/')->assertRedirect('/auto-reply');
    }

    public function test_the_inbox_is_gone(): void
    {
        foreach (['/conversations', '/conversations/1', '/session', '/inbox'] as $url) {
            $this->asAdmin()->get($url)->assertNotFound();
        }
        $this->asAdmin()->post('/sync')->assertNotFound();
    }

    public function test_the_page_shows_health_and_recent_attempts(): void
    {
        \App\Models\AutoReplyLog::create(['wa_message_id' => 'Z1', 'chat_id' => '628111@c.us', 'status' => 'failed', 'attempts' => 3, 'last_error' => 'OpenWA returned HTTP 503: down']);

        $this->asAdmin()->get('/auto-reply')->assertOk()->assertSee('628111')->assertSee('HTTP 503')->assertSee('Retry 1 failed reply');
    }

    public function test_the_page_warns_when_the_queue_is_synchronous(): void
    {
        config(['queue.default' => 'sync']);

        $this->asAdmin()->get('/auto-reply')->assertOk()->assertSee('QUEUE_CONNECTION=sync');
    }
}
