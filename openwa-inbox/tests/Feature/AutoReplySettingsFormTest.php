<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithOpenWa;
use Tests\TestCase;

class AutoReplySettingsFormTest extends TestCase
{
    use InteractsWithOpenWa;
    use RefreshDatabase;

    public function test_form_saves_text_switch_and_cooldown_for_one_conversation(): void
    {
        $one = $this->conversation('111@c.us');
        $two = $this->conversation('222@c.us', true, 'Untouched');

        $this->put(route('conversations.auto-reply', $one), [
            'auto_reply_enabled' => '1', 'auto_reply_text' => 'Back at 9am', 'auto_reply_cooldown_seconds' => '60',
        ])->assertRedirect(route('conversations.show', $one))->assertSessionHasNoErrors();

        $one->refresh();
        $this->assertTrue($one->auto_reply_enabled);
        $this->assertSame('Back at 9am', $one->auto_reply_text);
        $this->assertSame(60, $one->auto_reply_cooldown_seconds);

        $two->refresh();
        $this->assertTrue($two->auto_reply_enabled);
        $this->assertSame('Untouched', $two->auto_reply_text);
    }

    public function test_enabling_without_text_is_rejected_and_changes_nothing(): void
    {
        $c = $this->conversation('111@c.us');

        $this->from(route('conversations.show', $c))
            ->put(route('conversations.auto-reply', $c), ['auto_reply_enabled' => '1', 'auto_reply_text' => '   '])
            ->assertSessionHasErrors('auto_reply_text');

        $this->assertFalse($c->refresh()->auto_reply_enabled);
    }

    public function test_text_longer_than_the_limit_is_rejected(): void
    {
        $c = $this->conversation('111@c.us');

        $this->put(route('conversations.auto-reply', $c), ['auto_reply_enabled' => '1', 'auto_reply_text' => str_repeat('a', 4097)])
            ->assertSessionHasErrors('auto_reply_text');
    }

    public function test_invalid_cooldown_values_are_rejected(): void
    {
        $c = $this->conversation('111@c.us');

        $this->put(route('conversations.auto-reply', $c), ['auto_reply_text' => 'x', 'auto_reply_cooldown_seconds' => '-5'])
            ->assertSessionHasErrors('auto_reply_cooldown_seconds');
        $this->put(route('conversations.auto-reply', $c), ['auto_reply_text' => 'x', 'auto_reply_cooldown_seconds' => 'abc'])
            ->assertSessionHasErrors('auto_reply_cooldown_seconds');
        $this->put(route('conversations.auto-reply', $c), ['auto_reply_text' => 'x', 'auto_reply_cooldown_seconds' => '999999'])
            ->assertSessionHasErrors('auto_reply_cooldown_seconds');
    }

    public function test_disabling_keeps_the_saved_text_and_does_not_require_it(): void
    {
        $c = $this->conversation('111@c.us', true, 'Keep me');

        $this->put(route('conversations.auto-reply', $c), ['auto_reply_text' => 'Keep me'])->assertSessionHasNoErrors();
        $this->assertFalse($c->refresh()->auto_reply_enabled);
        $this->assertSame('Keep me', $c->auto_reply_text);

        $this->put(route('conversations.auto-reply', $c), [])->assertSessionHasNoErrors();
        $this->assertFalse($c->refresh()->auto_reply_enabled);
    }

    public function test_conversation_page_renders_the_form_with_current_values(): void
    {
        $c = $this->conversation('111@c.us', true, 'Saved reply text');

        $this->get(route('conversations.show', $c))
            ->assertOk()
            ->assertSee('Saved reply text')
            ->assertSee('name="auto_reply_enabled"', false)
            ->assertSee('checked', false);
    }
}
