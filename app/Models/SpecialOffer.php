<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;

class SpecialOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'nomcur',
        'coddis',
    ];

    public function schoolclasses()
    {
        $schoolterm = SchoolTerm::getLatest();

        return SchoolClass::whereBelongsTo($schoolterm)->where('coddis', $this->coddis)->get();
    }
}
