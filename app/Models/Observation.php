<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'school_term_id',
        'target',
    ];

    public function schoolterm()
    {
        return $this->belongsTo(SchoolTerm::class, "school_term_id");
    }
}
