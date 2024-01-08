<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Course;

class CourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Course::firstOrCreate(["sufixo_codtur"=>"43","nomcur"=>"Matemática - Bacharelado", "perhab"=>"diurno", "codcur"=>"45031"]);
        Course::firstOrCreate(["sufixo_codtur"=>"45","nomcur"=>"Bacharelado em Ciência da Computação", "perhab"=>"diurno", "codcur"=>"45052"]);
        Course::firstOrCreate(["sufixo_codtur"=>"46","nomcur"=>"Estatística - Bacharelado", "perhab"=>"diurno", "codcur"=>"45062"]);
        Course::firstOrCreate(["sufixo_codtur"=>"44","nomcur"=>"Matemática Aplicada - Bacharelado", "perhab"=>"diurno", "codcur"=>"45042"]);
        Course::firstOrCreate(["sufixo_codtur"=>"54","nomcur"=>"Bacharelado em Matemática Aplicada e Computacional", "perhab"=>"noturno", "codcur"=>"45070"]);
        Course::firstOrCreate(["sufixo_codtur"=>"42","nomcur"=>"Matemática - Licenciatura", "perhab"=>"diurno", "codcur"=>"45024"]);
        Course::firstOrCreate(["sufixo_codtur"=>"47","nomcur"=>"Matemática - Licenciatura", "perhab"=>"noturno", "grupo"=>"A", "codcur"=>"45024"]);
        Course::firstOrCreate(["sufixo_codtur"=>"48","nomcur"=>"Matemática - Licenciatura", "perhab"=>"noturno", "grupo"=>"B", "codcur"=>"45024"]);
    }
}
