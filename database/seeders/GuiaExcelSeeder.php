<?php

namespace Database\Seeders;

use App\Models\Guia_excel;
use Illuminate\Database\Seeder;

class GuiaExcelSeeder extends Seeder
{
    public function run()
    {
        // Crear 50 guías de prueba
        Guia_excel::factory()->count(20)->pendiente()->create();
        Guia_excel::factory()->count(15)->enProceso()->create();
        Guia_excel::factory()->count(10)->terminado()->create();
        Guia_excel::factory()->count(5)->state(['estado' => 'error'])->create();
    }
}
