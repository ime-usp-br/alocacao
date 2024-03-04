<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use \Datetime;
use \DateInterval;

class Reservation extends Model
{
    use HasFactory;

    protected $connection = 'urano';

    protected $table = 'RESERVA';
    
    public $timestamps = false;

    protected $fillable = [    
        "atividadeRegular",
        "data",
        "hi",
        "hf",
        "requisicao_id",
        "sala_numero",
    ];

    public static function checkAvailability($schoolclass)
    {
        if($schoolclass->room){
            $data_init = now();
            $data_fim = Datetime::createFromFormat("d/m/Y",$schoolclass->schoolterm->dtamaxres);

            if($schoolclass->room->nome == "Auditório Jacy Monteiro"){
                $sala = "AJM";
            }elseif($schoolclass->room->nome == "Auditório Antonio Gilioli"){
                $sala = "AAG";
            }else{
                $sala = strlen($schoolclass->room->nome) == 4 ? $schoolclass->room->nome : substr($schoolclass->room->nome,0,1)."0".substr($schoolclass->room->nome,1,2);
            }

            $data = $data_init;
            $dias = [1 => "seg",2 => "ter",3 => "qua",4 => "qui",5 => "sex",6 => "sab",7 => "dom"];
            while($data <= $data_fim){
                foreach($schoolclass->classschedules as $schedule){
                    if($schedule->diasmnocp != "sab" and $schedule->diasmnocp != "dom" and $schedule->diasmnocp == $dias[$data->format("N")]){
                        $reservations = Reservation::where(["data" => $data->format("Y-m-d"),"sala_numero" => $sala])->get();
                        foreach($reservations as $reservation){                            
                            if(!($schedule->horsai.":00" <= $reservation->hi or $schedule->horent.":00" >= $reservation->hf)){
                                return false;
                            }
                        }
                    }
                }
                $data = $data->add(new DateInterval("P1D"));
            }
            return true;
        }
        return false;
    }

    public static function createFrom($requisition, $schoolclass)
    {
        if($schoolclass->room){
            $data_init = new Datetime($requisition->dataInicio);
            $data_fim = new Datetime($requisition->dataFim);

            $ids = [];

            if($schoolclass->room->nome == "Auditório Jacy Monteiro"){
                $sala = "AJM";
            }elseif($schoolclass->room->nome == "Auditório Antonio Gilioli"){
                $sala = "AAG";
            }else{
                $sala = strlen($schoolclass->room->nome) == 4 ? $schoolclass->room->nome : substr($schoolclass->room->nome,0,1)."0".substr($schoolclass->room->nome,1,2);
            }

            $data = $data_init;
            while($data <= $data_fim){
                if($requisition->dom and $data->format("N")==7){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiDom;
                    $reservation->hf = $requisition->hfDom;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->seg and $data->format("N")==1){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiSeg;
                    $reservation->hf = $requisition->hfSeg;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->ter and $data->format("N")==2){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiTer;
                    $reservation->hf = $requisition->hfTer;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->qua and $data->format("N")==3){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiQua;
                    $reservation->hf = $requisition->hfQua;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->qui and $data->format("N")==4){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiQui;
                    $reservation->hf = $requisition->hfQui;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->sex and $data->format("N")==5){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiSex;
                    $reservation->hf = $requisition->hfSex;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                if($requisition->sab and $data->format("N")==6){
                    $reservation = new Reservation;
                    $reservation->atividadeRegular = 0;
                    $reservation->data = $data->format("Y-m-d");
                    $reservation->hi = $requisition->hiSab;
                    $reservation->hf = $requisition->hfSab;
                    $reservation->requisicao_id = $requisition->id;
                    $reservation->sala_numero = $sala;

                    $reservation->save();
                    array_push($ids, $reservation->id);
                }
                $data = $data->add(new DateInterval("P1D"));
            }

            return Reservation::whereIn("id", $ids)->get();
        }
    }
}
