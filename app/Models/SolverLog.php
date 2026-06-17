<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SolverLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_term_id',
        'job_id',
        'payload',
        'response',
        'status',
        'allocations_count',
        'unassigned_count',
        'manual_count',
        'dispatched_at',
        'responded_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'dispatched_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function schoolTerm()
    {
        return $this->belongsTo(SchoolTerm::class);
    }
}
