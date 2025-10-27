<?php

use App\Http\Controllers\GuiaExcelController;
use Illuminate\Support\Facades\Route;


Route::get('/', [GuiaExcelController::class, 'index'])->name('index');

Route::prefix('guias')->group(function () {
    Route::get('/', [GuiaExcelController::class, 'obtenerGuias']);
    Route::post('/procesar-excel', [GuiaExcelController::class, 'procesarExcel']);
    Route::post('/{id}/sincronizar', [GuiaExcelController::class, 'sincronizarGuia']);
    Route::post('/sincronizar-masiva', [GuiaExcelController::class, 'sincronizarMasiva']);

    // Ruta para eliminar guía individual
    Route::delete('/{id}', [GuiaExcelController::class, 'destroy'])->name('guias.destroy');

    // Ruta para eliminación múltiple de guías
    Route::delete('/', [GuiaExcelController::class, 'destroyMultiple'])->name('guias.destroyMultiple');
});
