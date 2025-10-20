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
    // Procesar archivo Excel subido
    public function procesarExcel(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:10240'
        ]);

        try {
            $archivo = $request->file('archivo');
            $nombreArchivo = $archivo->getClientOriginalName();

            // Cargar Excel
            $spreadsheet = IOFactory::load($archivo->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $filas = $worksheet->toArray();

            Log::info('=== BÚSQUEDA INTELIGENTE DE ENCABEZADOS ===', [
                'archivo' => $nombreArchivo,
                'total_filas' => count($filas)
            ]);

            // Buscar en TODA la hoja por palabras clave de "guía"
            $encabezadosEncontrados = $this->buscarEncabezadosInteligente($filas);

            if (!$encabezadosEncontrados) {
                throw new \Exception('No se encontraron encabezados válidos con "guía", "numero de guia" o similares en todo el archivo');
            }

            $filaEncabezados = $encabezadosEncontrados['fila'];
            $columnaGuia = $encabezadosEncontrados['columna_guia'];
            $mapeoColumnas = $encabezadosEncontrados['mapeo'];

            Log::info('Encabezados encontrados:', [
                'fila' => $filaEncabezados + 1,
                'columna_guia' => $columnaGuia,
                'mapeo_columnas' => $mapeoColumnas
            ]);

            // Procesar datos
            DB::beginTransaction();

            $guiasCreadas = 0;
            $guiasActualizadas = 0;
            $errores = [];

            // Procesar filas DESPUÉS de los encabezados
            for ($i = $filaEncabezados + 1; $i < count($filas); $i++) {
                $fila = $filas[$i];
                $numeroFila = $i + 1;

                try {
                    // Saltar filas completamente vacías
                    if (empty(array_filter($fila))) {
                        continue;
                    }

                    // Obtener número de guía usando la columna detectada
                    $numeroGuia = isset($fila[$columnaGuia]) ? trim($fila[$columnaGuia]) : '';

                    if (empty($numeroGuia)) {
                        continue;
                    }

                    Log::info("Procesando guía: $numeroGuia de fila $numeroFila");

                    // Extraer datos usando el mapeo de columnas detectado
                    $datosGuia = [
                        'numero_guia' => $numeroGuia,
                        'referencia' => $this->obtenerDatoColumna($fila, $mapeoColumnas, 'referencia'),
                        'destinatario' => $this->obtenerDatoColumna($fila, $mapeoColumnas, 'destinatario') ?: 'N/A',
                        'ciudad' => $this->obtenerDatoColumna($fila, $mapeoColumnas, 'ciudad'),
                        'direccion' => $this->obtenerDatoColumna($fila, $mapeoColumnas, 'direccion') ?: 'N/A',
                        'estado' => $this->normalizarEstado($this->obtenerDatoColumna($fila, $mapeoColumnas, 'estado') ?: 'pendiente'),
                        'fecha_consulta' => $this->procesarFecha($this->obtenerDatoColumna($fila, $mapeoColumnas, 'fecha')),
                        'archivo_origen' => $nombreArchivo
                    ];

                    // Verificar si ya existe
                    $guiaExistente = Guia_excel::where('numero_guia', $numeroGuia)->first();

                    if ($guiaExistente) {
                        $guiaExistente->update($datosGuia);
                        $guiasActualizadas++;
                    } else {
                        $nuevaGuia = Guia_excel::create($datosGuia);
                        $guiasCreadas++;
                    }
                } catch (\Exception $e) {
                    Log::error("Error en fila $numeroFila: " . $e->getMessage());
                    $errores[] = "Fila $numeroFila: " . $e->getMessage();
                }
            }

            DB::commit();

            // Obtener todas las guías para el frontend
            $todasLasGuias = Guia_excel::orderBy('created_at', 'desc')
                ->get()
                ->map(function ($guia) {
                    return $this->formatGuiaParaFrontend($guia);
                });

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
            Log::error('Error procesando Excel: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar Excel: ' . $e->getMessage()
            ], 500);
        }
    }

    // Busca encabezados de manera inteligente en toda la hoja
    // Si se requiere agregar más palabras clave, se pueden añadir al array $palabrasClaveGuia
    private function buscarEncabezadosInteligente($filas)
    {
        $palabrasClaveGuia = [
            'guia',
            'guía',
            'numero de guia',
            'número de guía',
            'numero_guia',
            'número_guía',
            'numero guia',
            'número guia',
            'número guía',
            'no. guia',
            'no. guía',
            'num guia',
            'num guía',
            'guide',
            'tracking'
        ];

        // Buscar en todas las filas y columnas
        for ($fila = 0; $fila < count($filas); $fila++) {
            $filaActual = $filas[$fila];

            for ($columna = 0; $columna < count($filaActual); $columna++) {
                $celda = $filaActual[$columna] ?? '';
                $celdaLimpia = strtolower(trim($celda));

                // Verificar si esta celda contiene alguna palabra clave de guía
                foreach ($palabrasClaveGuia as $palabraClave) {
                    if ($celdaLimpia === $palabraClave || strpos($celdaLimpia, $palabraClave) !== false) {

                        Log::info("¡Palabra clave encontrada!", [
                            'palabra' => $palabraClave,
                            'celda_original' => $celda,
                            'fila' => $fila + 1,
                            'columna' => $columna + 1,
                            'fila_completa' => $filaActual
                        ]);

                        // Mapear otras columnas en la misma fila
                        $mapeoColumnas = $this->mapearColumnasEncabezados($filaActual, $columna);

                        return [
                            'fila' => $fila,
                            'columna_guia' => $columna,
                            'mapeo' => $mapeoColumnas
                        ];
                    }
                }
            }
        }
        return false;
    }

    // Mapea las columnas basándose en los encabezados encontrados
    private function mapearColumnasEncabezados($filaEncabezados, $columnaGuia)
    {
        $mapeo = ['guia' => $columnaGuia];

        // Palabras clave para cada tipo de campo
        $palabrasClaveMapeo = [
            'referencia' => ['referencia', 'ref', 'reference', 'codigo', 'código'],
            'destinatario' => ['destinatario', 'cliente', 'nombre', 'receptor', 'consignatario'],
            'ciudad' => ['ciudad', 'city', 'municipio', 'localidad'],
            'direccion' => ['direccion', 'dirección', 'address', 'ubicacion', 'ubicación'],
            'estado' => ['estado', 'status', 'situacion', 'situación'],
            'fecha' => ['fecha', 'date', 'tiempo', 'time', 'consulta']
        ];

        // Buscar cada tipo de campo en los encabezados
        for ($i = 0; $i < count($filaEncabezados); $i++) {
            $encabezado = strtolower(trim($filaEncabezados[$i] ?? ''));

            foreach ($palabrasClaveMapeo as $campo => $palabrasClave) {
                foreach ($palabrasClave as $palabra) {
                    if (strpos($encabezado, $palabra) !== false) {
                        $mapeo[$campo] = $i;
                        Log::info("Campo mapeado: $campo en columna " . ($i + 1) . " ($encabezado)");
                        break 2; // Salir de ambos bucles
                    }
                }
            }
        }
        return $mapeo;
    }

    // Obtiene datos de una columna usando el mapeo
    private function obtenerDatoColumna($fila, $mapeoColumnas, $campo)
    {
        if (!isset($mapeoColumnas[$campo])) {
            return null;
        }

        $columna = $mapeoColumnas[$campo];
        return isset($fila[$columna]) ? trim($fila[$columna]) : null;
    }

    // ============ Método auxiliar para formatear guías para el frontend ==========
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

