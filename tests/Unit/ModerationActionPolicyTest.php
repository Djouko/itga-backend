<?php

namespace Tests\Unit;

use App\Services\ModerationActionPolicy;
use Tests\TestCase;

class ModerationActionPolicyTest extends TestCase
{
    public function test_known_actions_are_unique_and_ordered_from_config(): void
    {
        config()->set('moderation.actions', ['delete_post', 'delete_comment', 'delete_post']);

        $policy = new ModerationActionPolicy();

        $this->assertSame(['delete_post', 'delete_comment'], $policy->knownActions());
    }

    public function test_enabled_actions_resolve_wildcard_to_all_known_actions(): void
    {
        config()->set('moderation.actions', ['delete_post', 'delete_comment']);
        config()->set('moderation.enabled_actions', ['*']);

        $policy = new ModerationActionPolicy();

        $this->assertSame(['delete_post', 'delete_comment'], $policy->enabledActions());
        $this->assertTrue($policy->isEnabled('delete_post'));
    }

    public function test_unknown_or_disabled_action_is_not_enabled(): void
    {
        config()->set('moderation.actions', ['delete_post', 'delete_comment']);
        config()->set('moderation.enabled_actions', ['delete_comment']);

        $policy = new ModerationActionPolicy();

        $this->assertFalse($policy->isKnown('delete_story'));
        $this->assertFalse($policy->isEnabled('delete_story'));
        $this->assertFalse($policy->isEnabled('delete_post'));
        $this->assertTrue($policy->isEnabled('delete_comment'));
    }
}
