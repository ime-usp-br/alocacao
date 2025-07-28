<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchoolClass;
use Uspdev\Replicado\DB;

class ClassSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'diasmnocp',
        'horent',
        'horsai',
    ];

    public function schoolclasses()
    {
        return $this->belongsToMany(SchoolClass::class);
    }

    public static function getFromReplicadoBySchoolClass($schoolclass){
        $query = " SELECT O.diasmnocp, P.horent, P.horsai";
        $query .= " FROM OCUPTURMA AS O, PERIODOHORARIO AS P";
        $query .= " WHERE O.coddis = :coddis";
        $query .= " AND O.codtur = :codtur";
        $query .= " AND P.codperhor = O.codperhor";
        $param = [
            'coddis' => $schoolclass['coddis'],
            'codtur' => $schoolclass['codtur'],
        ];

        return array_unique(DB::fetchAll($query, $param),SORT_REGULAR);
    }

    public static function getFromReplicadoByPosSchoolClass($schoolclass){
        $query = " SELECT ET.diasmnofe as diasmnocp, ET.horiniofe as horent, ET.horfimofe as horsai";
        $query .= " FROM ESPACOTURMA AS ET";
        $query .= " WHERE ET.sgldis = :sgldis";
        $query .= " AND ET.numseqdis = :numseqdis";
        $query .= " AND ET.numofe = :numofe";
        $param = [
            'sgldis' => $schoolclass['coddis'],
            'numseqdis' => $schoolclass['numseqdis'],
            'numofe' => $schoolclass['numofe'],
        ];
        
        $days = ['1DM'=>'dom', '2SG'=>'seg', '3TR'=>'ter', '4QA'=>'qua', '5QI'=>'qui', '6SX'=>'sex', '7SB'=>'sab'];
        $res = array_unique(DB::fetchAll($query, $param),SORT_REGULAR);

        foreach($res as $key => $cs){
            $res[$key]['diasmnocp'] = $days[$res[$key]['diasmnocp']];
            $res[$key]['horent'] = substr($res[$key]['horent'], 0, 2) . ":" . substr($res[$key]['horent'], 2);
            $res[$key]['horsai'] = substr($res[$key]['horsai'], 0, 2) . ":" . substr($res[$key]['horsai'], 2);
        }
        return $res;

    }
}
