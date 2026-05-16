<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationAuditAppeal extends Model
{
    use HasFactory;

    public $table = 'moderation_audit_appeals';

    protected $fillable = [
        'audit_log_id',
        'appellant_user_id',
        'status',
        'reason',
        'details',
        'resolution_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function auditLog()
    {
        return $this->belongsTo(ModerationAuditLog::class, 'audit_log_id');
    }
}
