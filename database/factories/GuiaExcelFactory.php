<?php

namespace Database\Factories;

use App\Models\Guia_excel;
use Illuminate\Database\Eloquent\Factories\Factory;

class GuiaExcelFactory extends Factory
{
    protected $model = Guia_excel::class;

    public function definition(): array
    {
        return [
            'numero_guia' => $this->faker->unique()->numerify('##########'),
            'referencia' => $this->faker->numerify('REF-####'),
            'destinatario' => $this->faker->name(),
            'ciudad' => $this->faker->city(),
            'direccion' => $this->faker->address(),
            'estado' => $this->faker->randomElement(['pendiente', 'en_proceso', 'terminado', 'error']),
            'fecha_consulta' => $this->faker->optional()->dateTimeBetween('-30 days', 'now'),
            'observaciones' => $this->faker->optional()->sentence(),
            'archivo_origen' => 'test_data.xlsx',
        ];
    }

    public function pendiente()
    {
        return $this->state(['estado' => 'pendiente']);
    }

    public function enProceso()
    {
        return $this->state(['estado' => 'en_proceso']);
    }

    public function terminado()
    {
        return $this->state(['estado' => 'terminado']);
    }
}
