<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComparisonReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_term_id',
        'base_allocation_state_id',
        'status',
        'legacy_metrics',
        'solver_metrics',
        'legacy_raw_allocations',
        'solver_raw_allocations',
    ];

    protected $casts = [
        'legacy_metrics' => 'array',
        'solver_metrics' => 'array',
        'legacy_raw_allocations' => 'array',
        'solver_raw_allocations' => 'array',
    ];

    public function schoolTerm()
    {
        return $this->belongsTo(SchoolTerm::class);
    }

    public function baseAllocationState()
    {
        return $this->belongsTo(AllocationState::class, 'base_allocation_state_id');
    }
}
