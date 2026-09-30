<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastAuditEvent extends Model
{
    protected $table = 'farast_audit_events';

    protected $fillable = [
        'event_id','user_id','project_id','event_type','aggregate_type','aggregate_id',
        'source','payload_meta','payload_checksum','previous_checksum',
    ];

    protected function casts(): array
    {
        return ['payload_meta' => 'array'];
    }
}
