<?php

return [
    'actions' => [
        'delete_post',
        'delete_comment',
        'delete_room',
        'delete_story',
        'block_user',
        'delete_reel_comment',
        'delete_reel',
    ],

    // Comma-separated action keys. Example:
    // MODERATION_ENABLED_ACTIONS=delete_post,delete_comment
    // Default '*' means all actions from `actions` are enabled.
    'enabled_actions' => array_values(array_filter(array_map('trim', explode(',', (string) env('MODERATION_ENABLED_ACTIONS', '*'))))),

    // Sensitive export requires this flag and a legal_hold_reason.
    'allow_sensitive_export' => filter_var(env('MODERATION_AUDIT_ALLOW_SENSITIVE_EXPORT', false), FILTER_VALIDATE_BOOLEAN),
];
