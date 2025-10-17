<?php

namespace App\Http\Controllers;

use App\Models\Guia_excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;

class GuiaExcelController extends Controller
{
    public function index()
    {
        return view('index');
    }

    // API para obtener guías con filtros y paginación
    public function obtenerGuias(Request $request)
    {
        try {
            $query = Guia_excel::query();

            // Filtros
            if ($request->has('estado') && $request->estado !== null) {
                $query->porEstado($request->estado);
            }

            if ($request->has('fecha_desde')) {
                $query->where('created_at', '>=', $request->fecha_desde);
            }

            if ($request->has('fecha_hasta')) {
                $query->where('created_at', '<=', $request->fecha_hasta);
            }

            // Búsqueda general
            if ($request->has('buscar') && $request->buscar !== '') {
                $busqueda = $request->buscar;
                $query->where(function ($q) use ($busqueda) {
                    $q->where('numero_guia', 'LIKE', "%$busqueda%")
                      ->orWhere('destinatario', 'LIKE', "%$busqueda%")
                      ->orWhere('direccion', 'LIKE', "%$busqueda%")
                      ->orWhere('referencia', 'LIKE', "%$busqueda%")
                      ->orWhere('ciudad', 'LIKE', "%$busqueda%");
                });
            }

            // Ordenamiento
            $query->orderBy('created_at', 'desc');

            // Paginación
            $guias = $query->paginate($request->get('per_page', 10));

            return response()->json([
                'success' => true,
                'data' => $guias->items(),
                'pagination' => [
                    'current_page' => $guias->currentPage(),
                    'last_page' => $guias->lastPage(),
                    'per_page' => $guias->perPage(),
                    'total' => $guias->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener guías: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener guías'
            ], 500);
        }
    }
/*================================================================================================================= */
    // Procesar archivo Excel
    public function procesarExcel(Request $request) {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:10240'
        ]);

        try {
            DB::beginTransaction();

            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();

            // Leer Excel
            $spreadsheet = IOFactory::load($archivo->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $datos = $worksheet->toArray();

            // Validar escritura
            if (count($datos) < 2) {
                throw new \Exception('El archivo debe tener al menos una fila de datos además del encabezado');
            }

            // Procesar filas (asumiendo que la primera fila es el encabezado)
            $guiasCreadas = 0;
            $guiasActualizadas = 0;
            $errores = [];

            foreach (array_slice($datos, 1) as $index => $fila) {
                try {
                    // Validar fila
                    if (!isset($fila[0]) || $fila[0] === null || $fila[0] === '' || trim((string)$fila[0]) === '') {
                        Log::info("Saltando fila vacía en índice: " . ($index + 2));
                        continue;
                    }

                    $numeroGuia = trim($fila[0]);

                    // Buscar si ya existe
                    $guia = Guia_excel::where('numero_guia', $numeroGuia)->first();

                    $datosGuia = [
                        'numero_guia' => $numeroGuia,
                        'referencia' => $fila[1] ?? null,
                        'destinatario' => $fila[2] ?? 'N/A',
                        'ciudad' => $fila[3] ?? null,
                        'direccion' => $fila[4] ?? 'N/A',
                        'estado' => $this->normalizarEstado($fila[5] ?? 'pendiente'),
                        'fecha_consulta' => $this->procesarFecha($fila[6] ?? null),
                        'archivo_origen' => $nombreArchivo
                    ];

                    if ($guia) {
                        $guia->update($datosGuia);
                        $guiasActualizadas++;
                    } else {
                        Guia_excel::create($datosGuia);
                        $guiasCreadas++;
                    }
                } catch (\Exception $e) {
                    $errores[] = "Fila " . ($index + 2) . ": " . $e->getMessage();
                }
            }

            DB::commit();

            $todasLasGuias = Guia_excel::orderBy('created_at', 'desc')
                ->get()
                ->map(function ($guia) {
                    return $this->formatGuiaParaFrontend($guia);
                });

            Log::info('Guias formateadas para frontend:', $todasLasGuias->toArray());

            return response()->json([
                'success' => true,
                'message' => "Archivo procesado exitosamente",
                'data' => [
                    'guias_creadas' => $guiasCreadas,
                    'guias_actualizadas' => $guiasActualizadas,
                    'total_procesadas' => $guiasCreadas + $guiasActualizadas,
                    'guias' => $todasLasGuias,
                    'errores' => $errores
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar archivo Excel: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar archivo Excel: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método auxiliar para formatear guías para el frontend
    private function formatGuiaParaFrontend($guia)
    {
        $formatted = [
            'id' => $guia->id,
            'numero_guia' => $guia->numero_guia,
            'referencia' => $guia->referencia ?? 'N/A',
            'destinatario' => $guia->destinatario,
            'ciudad' => $guia->ciudad ?? 'N/A',
            'direccion' => $guia->direccion,
            'estado' => $guia->estado,
            'fecha_consulta_formateada' => $guia->fecha_consulta ?
                $guia->fecha_consulta->format('d-m-Y H:i') : 'Nunca',
            'puede_sincronizar' => $guia->puedeSerSincronizada()
        ];

        Log::info('Guía formateada:', $formatted);
        return $formatted;
    }

    /* ================================================================================================= */
    // Sincronizar guia individual
    public function sincronizarGuia(Request $request, $id)
    {
        try {
            $guia = Guia_excel::findOrFail($id);

            if (!$guia->puedeSerSincronizada()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La guía no puede ser sincronizada'
                ], 422);
            }

            // Simular llamada a API externa
            $estadoAnterior = $guia->estado;
            $nuevoEstado = $this->simularSincronizacionAPI($guia->numero_guia);

            $guia->marcarComoSincronizada($nuevoEstado);

            return response()->json([
                'success' => true,
                'message' => 'Guía sincronizada exitosamente',
                'data' => [
                    'id' => $guia->id,
                    'numero_guia' => $guia->numero_guia,
                    'estado_anterior' => $estadoAnterior,
                    'estado_nuevo' => $nuevoEstado,
                    'fecha_sincronizacion' => $guia->fecha_ultima_sincronizacion->format('d-m-Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error al sincronizar guía: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar guía'
            ], 500);
        }
    }
/* ================================================================================================= */
    // Sincronización masiva
    public function sincronizarMasiva(Request $request)
    {
        try {
            $guiasEnProceso = Guia_excel::enProceso()->get();

            if ($guiasEnProceso->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay guías en proceso para sincronizar'
                ], 422);
            }

            $resultados = [
                'exitosas' => 0,
                'fallidas' => 0,
                'detalles' => []
            ];

            foreach ($guiasEnProceso as $guia) {
                try {
                    $nuevoEstado = $this->simularSincronizacionAPI($guia->numero_guia);
                    $guia->marcarComoSincronizada($nuevoEstado);

                    $resultados['exitosas']++;
                    $resultados['detalles'][] = [
                        'numero_guia' => $guia->numero_guia,
                        'estado' => 'exitosa',
                        'nuevo_estado' => $nuevoEstado
                    ];
                } catch (\Exception $e) {
                    $resultados['fallidas']++;
                    $resultados['detalles'][] = [
                        'numero_guia' => $guia->numero_guia,
                        'estado' => 'fallida',
                        'error' => $e->getMessage()
                    ];
                }

                // Pausa pequeña para no saturar
                usleep(100000); // 0.1 segundos
            }

            return response()->json([
                'success' => true,
                'message' => 'Sincronización masiva completada',
                'data' => $resultados
            ]);
        } catch (\Exception $e) {
            Log::error('Error en sincronización masiva: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error en la sincronización masiva'
            ], 500);
        }
    }
    /* ================================================================================================= */
    // Métodos auxiliares privados
    private function normalizarEstado($estado)
    {
        $estado = strtolower(trim($estado));

        $mapeo = [
            'en proceso' => 'en_proceso',
            'enproceso' => 'en_proceso',
            'proceso' => 'en_proceso',
            'terminado' => 'terminado',
            'entregado' => 'terminado',
            'finalizado' => 'terminado',
            'pendiente' => 'pendiente',
            'error' => 'error',
            'fallido' => 'error',
            'cancelado' => 'cancelado'
        ];

        return $mapeo[$estado] ?? 'pendiente';
    }

    private function procesarFecha($fecha)
    {
        if (empty($fecha)) return null;

        try {
            return Carbon::parse($fecha);
        } catch (\Exception $e) {
                return null;
        }
    }

    private function simularSincronizacionAPI($numeroGuia)
    {
        // Simular llamada a API externa
        $estados = ['en_proceso', 'terminado', 'error'];
        $probabilidades = [60, 30, 10]; // Probabilidades en porcentaje

        $random = rand(1, 100);

        if ($random <= $probabilidades[2]) {
            return 'error';
        } elseif ($random <= $probabilidades[2] + $probabilidades[1]) {
            return 'terminado';
        } else {
            return 'en_proceso';
        }
    }
}

