<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Uspdev\Replicado\DB;
use App\Models\SchoolClass;
use App\Models\Course;
use \Datetime;
use \DateInterval;

class CourseInformation extends Model
{
    use HasFactory;

    protected $table = "course_informations";

    protected $fillable = [
        'nomcur',
        'codcur',
        'numsemidl',
        'perhab',
        'codhab',
        'nomhab',
        'tipobg',
    ];

    public function schoolclasses()
    {
        return $this->belongsToMany(SchoolClass::class);
    }

    //Foi criado um Model Course com essas informações, assim que possivel remover esse array
    static $codtur_by_course = [
        "43"=>["nomcur"=>"Matemática - Bacharelado", "perhab"=>"integral", "codcur"=>"45031"],
        "45"=>["nomcur"=>"Bacharelado em Ciência da Computação", "perhab"=>"integral", "codcur"=>"45052"],
        "46"=>["nomcur"=>"Estatística - Bacharelado", "perhab"=>"integral", "codcur"=>"45062"],
        "44"=>["nomcur"=>"Matemática Aplicada - Bacharelado", "perhab"=>"integral", "codcur"=>"45042"],
        "54"=>["nomcur"=>"Bacharelado em Matemática Aplicada e Computacional", "perhab"=>"noturno", "codcur"=>"45070"],
        "42"=>["nomcur"=>"Matemática - Licenciatura", "perhab"=>"matutino", "codcur"=>"45024"],
        "47"=>["nomcur"=>"Matemática - Licenciatura", "perhab"=>"noturno", "grupo"=>"A", "codcur"=>"45024"],
        "48"=>["nomcur"=>"Matemática - Licenciatura", "perhab"=>"noturno", "grupo"=>"B", "codcur"=>"45024"],
    ];

    public static function getFromReplicadoBySchoolClassAlternative($schoolclass)
    {
        $query = " SELECT CS.nomcur, CS.codcur, GC.numsemidl, HGR.codhab, HGR.nomhab, HGR.perhab, GC.tipobg";
        $query .= " FROM UNIDADE AS U, SETOR AS S, PREFIXODISCIP AS PD, CURSOGR as CS, HABILITACAOGR AS HGR, CURRICULOGR AS CGR, GRADECURRICULAR AS GC";
        $query .= " WHERE (GC.coddis = :coddis)";
        $query .= " AND GC.verdis = (SELECT MAX(GC2.verdis) 
                                    FROM GRADECURRICULAR AS GC2 
                                    WHERE GC2.coddis = :coddis)";
        $query .= " AND CGR.codcrl = GC.codcrl";
        $query .= " AND CGR.sitcrl = :sitcrl";
        $query .= " AND HGR.codcur = CGR.codcur";
        $query .= " AND HGR.codhab = CGR.codhab";
        $query .= " AND HGR.dtadtvhab IS NULL";
        $query .= " AND CS.codcur = CGR.codcur";
        $query .= " AND PD.codclg = CS.codclg";
        $query .= " AND S.codset = PD.codset";
        $query .= " AND U.codund = S.codund";
        $query .= " AND U.sglund LIKE :sglund";
        $param = [
            'sglund' => env("UNIDADE"),
            'coddis' => $schoolclass->coddis,
            'sitcrl' => "AT",
        ];

        $res =  DB::fetchAll($query, $param);

        return array_unique($res,SORT_REGULAR);
    }

    public static function getFromReplicadoBySchoolClass($schoolclass)
    {
        $query = " SELECT CS.nomcur, CS.codcur, GC.numsemidl, HGR.codhab, HGR.nomhab, HGR.perhab, GC.tipobg";
        $query .= " FROM HABILTURMA AS HT, CURSOGR as CS, HABILITACAOGR AS HGR, CURRICULOGR AS CGR, GRADECURRICULAR AS GC";
        $query .= " WHERE (HT.coddis = :coddis)";
        $query .= " AND HT.codtur LIKE :codtur";
        $query .= " AND HT.verdis = (SELECT MAX(HT2.verdis) 
                                    FROM HABILTURMA AS HT2 
                                    WHERE HT2.coddis = :coddis)";
        $query .= " AND CS.codcur = HT.codcur";
        $query .= " AND HGR.codcur = HT.codcur";
        $query .= " AND HGR.codhab = HT.codhab";
        $query .= " AND HGR.dtadtvhab IS NULL";
        $query .= " AND CGR.codcur = HT.codcur";
        $query .= " AND CGR.codhab = HT.codhab";
        $query .= " AND CGR.sitcrl = :sitcrl";
        $query .= " AND GC.codcrl = CGR.codcrl";
        $query .= " AND GC.coddis = HT.coddis";
        $query .= " AND GC.verdis = HT.verdis";
        $param = [
            'coddis' => $schoolclass->coddis,
            'codtur' => $schoolclass->codtur,
            'sitcrl' => "AT",
        ];

        $res = DB::fetchAll($query, $param);

        if(!$res and in_array(substr($schoolclass->codtur,-2,2),array_keys(self::$codtur_by_course))){
            $query = " SELECT CS.nomcur, CS.codcur, GC.numsemidl, HGR.codhab, HGR.nomhab, HGR.perhab, GC.tipobg";
            $query .= " FROM CURSOGR as CS, HABILITACAOGR AS HGR, CURRICULOGR AS CGR, GRADECURRICULAR AS GC";
            $query .= " WHERE (GC.coddis = :coddis)";
            $query .= " AND GC.verdis = (SELECT MAX(GC2.verdis) 
                                        FROM GRADECURRICULAR AS GC2 
                                        WHERE GC2.coddis = :coddis)";
            $query .= " AND CGR.codcrl = GC.codcrl";
            $query .= " AND CGR.codcur = :codcur";
            $query .= " AND CGR.sitcrl = :sitcrl";
            $query .= " AND HGR.codcur = :codcur";
            $query .= " AND HGR.codhab = CGR.codhab";
            $query .= " AND HGR.perhab = :perhab";
            $query .= " AND HGR.dtadtvhab IS NULL";
            $query .= " AND CS.codcur = :codcur";
            $param = [
                'coddis' => $schoolclass->coddis,
                'codcur' => self::$codtur_by_course[substr($schoolclass->codtur,-2,2)]["codcur"],
                'perhab' => self::$codtur_by_course[substr($schoolclass->codtur,-2,2)]["perhab"],
                'sitcrl' => "AT",
            ];

            $res = DB::fetchAll($query, $param);
        }

        if(!$res and (substr($schoolclass->codtur,-2,2) == "41" or substr($schoolclass->codtur,-2,2) == "51") and !$schoolclass->externa){
            $query = " SELECT CS.nomcur, CS.codcur, GC.numsemidl, HGR.codhab, HGR.nomhab, HGR.perhab, GC.tipobg";
            $query .= " FROM UNIDADE AS U, SETOR AS S, PREFIXODISCIP AS PD, CURSOGR as CS, HABILITACAOGR AS HGR, CURRICULOGR AS CGR, GRADECURRICULAR AS GC";
            $query .= " WHERE (GC.coddis = :coddis)";
            $query .= " AND GC.verdis = (SELECT MAX(GC2.verdis) 
                                        FROM GRADECURRICULAR AS GC2 
                                        WHERE GC2.coddis = :coddis)";
            $query .= " AND CGR.codcrl = GC.codcrl";
            $query .= " AND CGR.sitcrl = :sitcrl";
            $query .= " AND HGR.codcur = CGR.codcur";
            $query .= " AND HGR.codhab = CGR.codhab";
            $query .= " AND HGR.dtadtvhab IS NULL";
            $query .= " AND CS.codcur = CGR.codcur";
            $query .= " AND PD.codclg = CS.codclg";
            $query .= " AND S.codset = PD.codset";
            $query .= " AND U.codund = S.codund";
            $query .= " AND U.sglund LIKE :sglund";
            $param = [
                'sglund' => env("UNIDADE"),
                'coddis' => $schoolclass->coddis,
                'sitcrl' => "AT",
            ];
    
            $res =  DB::fetchAll($query, $param);
        }

        return array_unique($res,SORT_REGULAR);
    }
}
