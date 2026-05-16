<?php

namespace App\Services;

class ModerationActionPolicy
{
    public function knownActions(): array
    {
        return array_values(array_unique((array) config('moderation.actions', [])));
    }

    public function enabledActions(): array
    {
        $configured = array_values(array_filter((array) config('moderation.enabled_actions', ['*'])));

        if (in_array('*', $configured, true)) {
            return $this->knownActions();
        }

        return array_values(array_intersect($this->knownActions(), $configured));
    }

    public function isKnown(string $action): bool
    {
        return in_array($action, $this->knownActions(), true);
    }

    public function isEnabled(string $action): bool
    {
        if (!$this->isKnown($action)) {
            return false;
        }

        return in_array($action, $this->enabledActions(), true);
    }
}
