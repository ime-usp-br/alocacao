<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Instructor;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\Priority;
use App\Models\Fusion;
use App\Models\CourseInformation;
use Uspdev\Replicado\DB;
use Carbon\Carbon;

class SchoolClass extends Model
{
    use HasFactory;
    public $cor;

    protected $fillable = [
        'codtur',
        'tiptur',
        'nomdis',
        'coddis',
        'estmtr',
        'externa',
        'dtainitur',
        'dtafimtur',
        'school_term_id',
        'room_id',
        'fusion_id',
    ];

    protected $casts = [
        'dtainitur' => 'date:d/m/Y',
        'dtafimtur' => 'date:d/m/Y',
    ];

    public function setDtainiturAttribute($value)
    {
        $this->attributes['dtainitur'] = Carbon::createFromFormat('d/m/Y', $value);
    }

    public function setDtafimturAttribute($value)
    {
        $this->attributes['dtafimtur'] = Carbon::createFromFormat('d/m/Y', $value);
    }

    public function getDtainiturAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '';
    }

    public function getDtafimturAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '';
    }

    public function schoolterm()
    {
        return $this->belongsTo(SchoolTerm::class, "school_term_id");
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function instructors()
    {
        return $this->belongsToMany(Instructor::class);
    }

    public function classschedules()
    {
        return $this->belongsToMany(ClassSchedule::class);
    }

    public function priorities()
    {
        return $this->hasMany(Priority::class);
    }

    public function fusion()
    {
        return $this->belongsTo(Fusion::class, "fusion_id");
    }

    public function courseinformations()
    {
        return $this->belongsToMany(CourseInformation::class);
    }

    public function isInConflict($turma)
    {
        foreach($this->classschedules as $cs1){
            foreach($turma->classschedules as $cs2){
                if($cs1->diasmnocp == $cs2->diasmnocp){
                    if(!($cs1->horsai <= $cs2->horent or $cs1->horent >= $cs2->horsai)){
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public function searchForFusion()
    {
        $conflicts = [];
        $fusion = false;
        $schoolclasses = SchoolClass::whereBelongsTo($this->schoolterm)->whereHas("instructors", function($query){
            $query->whereIn("id",$this->instructors()->pluck("id")->toArray());
        })->where("id","!=",$this->id)->where("externa", false)->get();

        foreach($schoolclasses as $sc2){
            if($this->isInConflict($sc2) and $this->instructors->diff($sc2->instructors)->isEmpty() and $sc2->instructors->diff($this->instructors)->isEmpty()){
                array_push($conflicts, $sc2->id);
                if($sc2->fusion()->exists()){
                    $fusion = $sc2->fusion;
                }
            }
        }

        if($conflicts){
            if(!$fusion){
                $fusion = new Fusion;
                if(SchoolClass::whereIn("id", $conflicts)->where("tiptur", "Graduação")->exists()){
                    $fusion->master()->associate(SchoolClass::whereIn("id", $conflicts)->where("tiptur", "Graduação")->first());
                }else{
                    $fusion->master()->associate($this);
                }
                $fusion->save();
                foreach($conflicts as $id){
                    $sc2 = SchoolClass::find($id); 
                    $sc2->fusion()->associate($fusion); 
                    $sc2->save();
                }
            }
            $this->fusion()->associate($fusion);
            $this->save();
        }
    }

    public static function getGrdDisciplinesFromReplicadoByInstitute($sglund){
        $query = " SELECT DC.coddis";
        $query .= " FROM UNIDADE AS U, SETOR AS S, PREFIXODISCIP AS PD, DISCIPGRCODIGO AS DC";
        $query .= " WHERE (U.sglund LIKE :sglund)";
        $query .= " AND S.codund = U.codund";
        $query .= " AND PD.codset = S.codset";
        $query .= " AND DC.codclg = PD.codclg";
        $param = [
            'sglund' => $sglund,
        ];

        return array_unique(DB::fetchAll($query, $param),SORT_REGULAR);

    }

    public function calcEstimadedEnrollment()
    {
        $query = " SELECT (T.numins+T.numinscpl+T.numinsopt+T.numinsecr+T.numinsoptlre) AS TOTALINSCRITOS";
        $query .= " FROM TURMAGR AS T";
        $query .= " WHERE (T.coddis = :coddis)";
        $query .= " AND T.codtur LIKE :codtur";
        $query .= " AND T.verdis = (SELECT MAX(T.verdis) 
                                    FROM TURMAGR AS T 
                                    WHERE T.coddis = :coddis)";
        $param = [
            'coddis' => $this->coddis,
            'codtur' => $this->codtur,
        ];

        $res = DB::fetchAll($query, $param);

        $this->estmtr = $res ? $res[0]["TOTALINSCRITOS"] : null;
    }
    
    public static function getExternalDisciplinesFromReplicadoByCourse($course)
    {

        $query = " SELECT GC.coddis";
        $query .= " FROM UNIDADE AS U, SETOR AS S, PREFIXODISCIP AS PD, CURSOGR as CS, HABILITACAOGR AS HGR, CURRICULOGR AS CGR, GRADECURRICULAR AS GC, DISCIPGRCODIGO AS DGRC";
        $query .= " WHERE CS.codcur = :codcur";
        $query .= " AND HGR.codcur = CS.codcur";
        $query .= " AND HGR.perhab = :perhab";
        $query .= " AND CGR.codcur = HGR.codcur";
        $query .= " AND CGR.codhab = HGR.codhab";
        $query .= " AND CGR.sitcrl = :sitcrl";
        $query .= " AND GC.codcrl = CGR.codcrl";
        $query .= " AND DGRC.coddis = GC.coddis";
        $query .= " AND PD.codclg = DGRC.codclg";
        $query .= " AND S.codset = PD.codset";
        $query .= " AND U.codund = S.codund";
        $query .= " AND U.sglund NOT LIKE :sglund";
        $param = [
            'sglund' => env("UNIDADE"),
            'codcur' => $course["codcur"],
            'perhab' => $course["perhab"],
            'sitcrl' => "AT",
        ];

        return array_column(array_unique(DB::fetchAll($query, $param),SORT_REGULAR), "coddis");
    }

    public static function getFromReplicadoBySchoolTerm(SchoolTerm $schoolTerm)
    {
        $disciplinas = SELF::getGrdDisciplinesFromReplicadoByInstitute(env("UNIDADE"));

        $schoolclasses = [];

        $dtafimtur = $schoolTerm->period == "1° Semestre" ? $schoolTerm->year."-06-01" : $schoolTerm->year."-11-01";

        foreach($disciplinas as $disc){
            $coddis = $disc['coddis'];

            $codtur = $schoolTerm->year . ($schoolTerm->period == "1° Semestre" ? "1%" : "2%");

            $query = " SELECT T.codtur, T.coddis, D.nomdis, T.dtainitur, T.dtafimtur, DC.pfxdisval";
            $query .= " FROM TURMAGR AS T, DISCIPLINAGR AS D, DISCIPGRCODIGO AS DC";
            $query .= " WHERE (T.coddis = :coddis)";
            $query .= " AND T.codtur LIKE :codtur";
            $query .= " AND T.statur = :statur";
            $query .= " AND T.verdis = (SELECT MAX(T2.verdis) 
                                        FROM TURMAGR AS T2 
                                        WHERE T2.coddis = :coddis 
                                        AND T2.statur = :statur)";
            $query .= " AND D.coddis = T.coddis";
            $query .= " AND D.verdis = T.verdis";
            $query .= " AND DC.coddis = T.coddis";
            $param = [
                'coddis' => $coddis,
                'codtur' => $codtur,
                'statur' => "A",
            ];

            $turmas = DB::fetchAll($query, $param);
            
            foreach($turmas as $key => $turma){
                $turmas[$key]['class_schedules'] = ClassSchedule::getFromReplicadoBySchoolClass($turma);
                $turmas[$key]['instructors'] = Instructor::getFromReplicadoBySchoolClass($turma);
                $turmas[$key]['school_term_id'] = $schoolTerm->id;
                $turmas[$key]['dtainitur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtainitur"])->format("d/m/Y");
                $turmas[$key]['dtafimtur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtafimtur"])->format("d/m/Y");
                $turmas[$key]['tiptur'] = "Graduação";
                $turmas[$key]['externa'] = false;
                unset($turmas[$key]['pfxdisval']);

            }

            $schoolclasses = array_merge($schoolclasses, $turmas);

            $codtur = $schoolTerm->period == "1° Semestre" ? ($schoolTerm->year-1)."2%" : $schoolTerm->year."1%";

            $query = " SELECT T.codtur, T.coddis, D.nomdis, T.dtainitur, T.dtafimtur";
            $query .= " FROM TURMAGR AS T, DISCIPLINAGR AS D, DISCIPGRCODIGO AS DC";
            $query .= " WHERE (T.coddis = :coddis)";
            $query .= " AND T.codtur LIKE :codtur";
            $query .= " AND T.statur = :statur";
            $query .= " AND T.verdis = (SELECT MAX(T2.verdis) 
                                        FROM TURMAGR AS T2 
                                        WHERE T2.coddis = :coddis 
                                        AND T2.statur = :statur
                                        AND T2.codtur LIKE :codtur)";
            $query .= " AND T.dtafimtur > :dtafimtur";
            $query .= " AND D.coddis = T.coddis";
            $query .= " AND D.verdis = T.verdis";
            $query .= " AND DC.coddis = T.coddis";
            $param = [
                'coddis' => $coddis,
                'codtur' => $codtur,
                'statur' => "A",
                'dtafimtur' => $dtafimtur,
            ];

            $turmas = DB::fetchAll($query, $param);
            
            foreach($turmas as $key => $turma){
                $turmas[$key]['class_schedules'] = ClassSchedule::getFromReplicadoBySchoolClass($turma);
                $turmas[$key]['instructors'] = Instructor::getFromReplicadoBySchoolClass($turma);
                $turmas[$key]['school_term_id'] = $schoolTerm->id;
                $turmas[$key]['dtainitur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtainitur"])->format("d/m/Y");
                $turmas[$key]['dtafimtur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtafimtur"])->format("d/m/Y");
                $turmas[$key]['tiptur'] = "Graduação";
                $turmas[$key]['externa'] = false;

            }
            
            $schoolclasses = array_merge($schoolclasses, $turmas);
        }

        $codtur = $schoolTerm->year . ($schoolTerm->period == "1° Semestre" ? "1%" : "2%");

        foreach(CourseInformation::$codtur_by_course as $sufixo_codtur=>$course){
            foreach(SELF::getExternalDisciplinesFromReplicadoByCourse($course) as $coddis){
                $query = " SELECT T.codtur, T.coddis, D.nomdis, T.dtainitur, T.dtafimtur";
                $query .= " FROM TURMAGR AS T, DISCIPLINAGR AS D, DISCIPGRCODIGO AS DC";
                $query .= " WHERE (T.coddis = :coddis)";
                $query .= " AND T.codtur LIKE :codtur";
                $query .= " AND T.statur = :statur";
                $query .= " AND T.verdis = (SELECT MAX(T2.verdis) 
                                            FROM TURMAGR AS T2 
                                            WHERE T2.coddis = :coddis
                                            AND T2.statur = :statur)";
                $query .= " AND D.coddis = T.coddis";
                $query .= " AND D.verdis = T.verdis";
                $query .= " AND DC.coddis = T.coddis";
                $param = [
                    'coddis' => $coddis,
                    'codtur' => $codtur,
                    'statur' => "A",
                ];

                $turmas = DB::fetchAll($query, $param);
                
                foreach($turmas as $key => $turma){
                    $turmas[$key]['class_schedules'] = ClassSchedule::getFromReplicadoBySchoolClass($turma);
                    $turmas[$key]['instructors'] = Instructor::getFromReplicadoBySchoolClass($turma);
                    $turmas[$key]['school_term_id'] = $schoolTerm->id;
                    $turmas[$key]['dtainitur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtainitur"])->format("d/m/Y");
                    $turmas[$key]['dtafimtur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtafimtur"])->format("d/m/Y");
                    $turmas[$key]['tiptur'] = "Graduação";
                    $turmas[$key]['externa'] = true;
    
                }
                $schoolclasses = array_merge($schoolclasses, $turmas);
            }
        }

        $query = " SELECT U.codund";
        $query .= " FROM UNIDADE AS U";
        $query .= " WHERE (U.sglund = :sglund)";
        $param = [
            'sglund' => env("UNIDADE"),
        ];

        $codund = array_unique(DB::fetchAll($query, $param),SORT_REGULAR)[0]['codund'];

        $query = " SELECT D.sgldis as coddis, D.nomdis, D.numseqdis, O.numofe, O.dtainiofe as dtainitur, O.dtafimofe as dtafimtur";
        $query .= " FROM DISCIPLINA AS D, OFERECIMENTO AS O";
        $query .= " WHERE (D.codare LIKE :codare)";
        $query .= " AND D.numseqdis = (SELECT MAX(D2.numseqdis) 
                                    FROM DISCIPLINA AS D2 
                                    WHERE D2.sgldis = D.sgldis)";
        $query .= " AND O.sgldis = D.sgldis";
        $query .= " AND O.numseqdis = D.numseqdis";
        $query .= " AND O.fmtofe = :fmtofe";
        $query .= " AND O.dtacantur IS NULL";
        $query .= " AND O.dtainiofe >= :dtainimin";
        $query .= " AND O.dtafimofe <= :dtafimmax";
        $param = [
            'codare' => $codund . '%',
            'fmtofe' => 'P',
            'dtainimin' => $schoolTerm->year . ($schoolTerm->period == "1° Semestre" ? '-01-01' : '-07-01'),
            'dtafimmax' => $schoolTerm->year . ($schoolTerm->period == "1° Semestre" ? '-07-31' : '-12-31'),
        ];

        $turmas = array_unique(DB::fetchAll($query, $param),SORT_REGULAR);

        foreach($turmas as $key => $turma){
            $turmas[$key]['class_schedules'] = ClassSchedule::getFromReplicadoByPosSchoolClass($turma);
            $turmas[$key]['instructors'] = Instructor::getFromReplicadoByPosSchoolClass($turma);
            $turmas[$key]['school_term_id'] = $schoolTerm->id;
            $turmas[$key]['dtainitur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtainitur"])->format("d/m/Y");
            $turmas[$key]['dtafimtur'] = Carbon::createFromFormat("Y-m-d H:i:s", $turma["dtafimtur"])->format("d/m/Y");
            $turmas[$key]['tiptur'] = "Pós Graduação";
            $turmas[$key]['codtur'] = $schoolTerm->year . ($schoolTerm->period == "1° Semestre" ? "1" : "2") . $turmas[$key]['numseqdis'] . $turmas[$key]['numofe'];
            $turmas[$key]['externa'] = false;
            unset($turmas[$key]['numseqdis']);
            unset($turmas[$key]['numofe']);
        }

        $schoolclasses = array_merge($schoolclasses, $turmas);
        
        return $schoolclasses;
    }
}
