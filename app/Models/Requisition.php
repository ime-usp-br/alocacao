<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use \Datetime;
use \DateInterval;

class Requisition extends Model
{
    use HasFactory;

    protected $connection = 'urano';

    protected $table = 'REQUISICAO';
    
    public $timestamps = false;

    protected $fillable = [    
        "atividade",
        "atividadeRegular",
        "auditorio",
        "comum",
        "dataCadastro",
        "dataFim",
        "dataInicio",
        "dom",
        "email",
        "frequencia",
        "hfDom",
        "hfQua",
        "hfQui",
        "hfSab",
        "hfSeg",
        "hfSex",
        "hfTer",
        "hiDom",
        "hiQua",
        "hiQui",
        "hiSab",
        "hiSeg",
        "hiSex",
        "hiTer",
        "participantes",
        "qua",
        "qui",
        "ramal",
        "solicitante",
        "sab",
        "seg",
        "sex",
        "status",
        "ter",
        "titulo",
        "videoConf",
        "usuario",
    ];

    protected $casts = [
        'dataCadastro' => 'date:Y-m-d H:i:s',
        'dataFim' => 'date:Y-m-d',
        'dataInicio' => 'date:Y-m-d',
    ];

    public static function createFromSchoolClass($schoolclass)
    {
        $requisition = New Requisition;

        $requisition->atividade = 1;
        $requisition->atividadeRegular = 0;

        if($schoolclass->room->nome == "Auditório Jacy Monteiro" or $schoolclass->room->nome == "Auditório Antonio Gilioli"){
            $requisition->auditorio = 1;
        }else{
            $requisition->auditorio = 0;
        }
        
        $requisition->comum = 1;
        $requisition->dataCadastro = date("Y-m-d H:i:s");
        $requisition->dataInicio = date("Y-m-d");
        $requisition->dataFim = Carbon::createFromFormat('d/m/Y', $schoolclass->schoolterm->dtamaxres)->format("Y-m-d");
        $requisition->email = "sistemadealocacao@gmail.com";
        $requisition->frequencia = "MULTIPLA";
        $requisition->participantes = $schoolclass->estmtr ?? 0;
        $requisition->ramal = "";
        $requisition->solicitante = $schoolclass->instructors()->first()->nompes ?? "";
        $requisition->status = 1;

        $titulo = "";
        if($schoolclass->fusion){
            if($schoolclass->fusion->schoolclasses->pluck("coddis")->unique()->count() == 1){
                $titulo .= $schoolclass->fusion->schoolclasses[0]->coddis." ";
                foreach(range(0, count($schoolclass->fusion->schoolclasses)-1) as $y){
                    $titulo .= "T.".substr($schoolclass->fusion->schoolclasses[$y]->codtur, -2, 2);
                    $titulo .= $y != count($schoolclass->fusion->schoolclasses)-1 ? "/" : "";

                }
            }elseif($schoolclass->fusion->schoolclasses()->where("tiptur","Graduação")->get()->count() == $schoolclass->fusion->schoolclasses->count()){
                foreach(range(0, count($schoolclass->fusion->schoolclasses)-1) as $y){
                    $titulo .= $schoolclass->fusion->schoolclasses[$y]->coddis." T.".substr($schoolclass->fusion->schoolclasses[$y]->codtur, -2, 2);
                    $titulo .= $y != count($schoolclass->fusion->schoolclasses)-1 ? "/" : "";
                }

            }else{
                foreach(range(0, count($schoolclass->fusion->schoolclasses)-1) as $y){
                    $titulo .= $schoolclass->fusion->schoolclasses[$y]->coddis;
                    $titulo .= $y != count($schoolclass->fusion->schoolclasses)-1 ? "/" : "";
                }
                $titulo .= " T.".substr($schoolclass->fusion->master->codtur, -2, 2);
            }
        }elseif($schoolclass->tiptur == "Pós Graduação"){
            $titulo = $schoolclass->coddis." T.00";
        }else{
            $titulo = $schoolclass->coddis." T.".substr($schoolclass->codtur,-2,2);
        }

        $requisition->titulo = $titulo;
        $requisition->videoConf = 0;
        $requisition->usuario = env("URANO_USER_ID");
        $requisition->dom = 0;
        $requisition->seg = 0;
        $requisition->ter = 0;
        $requisition->qua = 0;
        $requisition->qui = 0;
        $requisition->sex = 0;
        $requisition->sab = 0;

        foreach($schoolclass->classschedules as $schedule){
            if($schedule->diasmnocp == "dom"){
                $requisition->dom = 1;
                $requisition->hiDom = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfDom = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{                    
                    $requisition->hfDom = (new Datetime($schedule->horsai))->format("H:i:s");
                }
            }elseif($schedule->diasmnocp == "seg"){
                $requisition->seg = 1;
                $requisition->hiSeg = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfSeg = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{          
                    $requisition->hfSeg = (new Datetime($schedule->horsai))->format("H:i:s");          
                }
            }elseif($schedule->diasmnocp == "ter"){
                $requisition->ter = 1;
                $requisition->hiTer = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfTer = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{         
                    $requisition->hfTer = (new Datetime($schedule->horsai))->format("H:i:s");           
                }
            }elseif($schedule->diasmnocp == "qua"){
                $requisition->qua = 1;
                $requisition->hiQua = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfQua = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{                    
                    $requisition->hfQua = (new Datetime($schedule->horsai))->format("H:i:s");
                }
            }elseif($schedule->diasmnocp == "qui"){
                $requisition->qui = 1;
                $requisition->hiQui = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfQui = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{                    
                    $requisition->hfQui = (new Datetime($schedule->horsai))->format("H:i:s");
                }
            }elseif($schedule->diasmnocp == "sex"){
                $requisition->sex = 1;
                $requisition->hiSex = (new Datetime($schedule->horent))->format("H:i:s");
                if(explode(":", $schedule->horsai)[1] == "00"){
                    $requisition->hfSex = (new Datetime($schedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i:s");
                }else{                    
                    $requisition->hfSex = (new Datetime($schedule->horsai))->format("H:i:s");
                }
            }
        }

        $requisition->save();

        return $requisition;
    }
}
