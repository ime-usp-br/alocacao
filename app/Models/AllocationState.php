<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllocationState extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_term_id',
        'name',
        'allocations',
        'solver_log_id',
    ];

    protected $casts = [
        'allocations' => 'array',
    ];

    public function schoolTerm()
    {
        return $this->belongsTo(SchoolTerm::class);
    }

    public function solverLog()
    {
        return $this->belongsTo(SolverLog::class);
    }
}
