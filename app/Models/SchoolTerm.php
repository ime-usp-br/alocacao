<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolClass;
use Carbon\Carbon;

class SchoolTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'period',
        'dtamaxres',
    ];

    protected $casts = [
        'dtamaxres' => 'date:d/m/Y',
    ];

    public function setDtamaxresAttribute($value)
    {
        $this->attributes['dtamaxres'] = Carbon::createFromFormat('d/m/Y', $value);
    }

    public function getDtamaxresAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '';
    }

    public function schoolclasses()
    {
        return $this->hasMany(SchoolClass::class);
    }

    public static function getLatest()
    {
        $year = SchoolTerm::max("year");
        $period = SchoolTerm::where("year",$year)->max("period");
        return SchoolTerm::where(["year"=>$year,"period"=>$period])->first();
    } 
}
