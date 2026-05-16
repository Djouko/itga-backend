<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationAuditLog extends Model
{
    use HasFactory;

    public $table = 'moderation_audit_logs';

    protected $fillable = [
        'moderator_user_id',
        'action',
        'target_type',
        'target_id',
        'target_owner_user_id',
        'status',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
