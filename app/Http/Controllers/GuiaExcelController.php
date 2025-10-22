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
                    if (empty(array_filter($fila))) continue;

                    // Obtener número de guía usando la columna detectada
                    $numeroGuia = isset($fila[$columnaGuia]) ? trim($fila[$columnaGuia]) : '';

                    if (empty($numeroGuia)) {
                        Log::warning("Saltando fila {$numeroFila} por número de guía vacío");
                        $errores[] = "Fila {$numeroFila}: Número de guía vacío.";
                        continue; // Saltar si el número de guía está vacío
                    }

                    Log::info("Procesando guía: $numeroGuia de fila $numeroFila");

                    // Extraer datos usando el mapeo de columnas detectado
                    $datosExcel = [
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
                        // Detectar cambios - comparar estados y fechas
                        $huboCambios = $this->detectarCambiosEnGuia($guiaExistente, $datosExcel);

                        if ($huboCambios) {
                            // Actualizar con registro de sincronización
                            $this->actualizarGuiaConSincronizacion($guiaExistente, $datosExcel);
                            $guiasActualizadas++;

                            Log::info("Guía $numeroGuia SINCRONIZADA - Cambios detectados", [
                                'estado_anterior' => $guiaExistente->estado,
                                'estado_nuevo' => $datosExcel['estado'],
                                'fecha_anterior' => $guiaExistente->fecha_consulta,
                                'fecha_nueva' => $datosExcel['fecha_consulta']
                            ]);
                        } else {
                            // Solo actualizar campos no criticos sin contar como sincronización
                            $guiaExistente->update([
                                'referencia' => $datosExcel['referencia'],
                                'destinatario' => $datosExcel['destinatario'],
                                'ciudad' => $datosExcel['ciudad'],
                                'direccion' => $datosExcel['direccion'],
                                'archivo_origen' => $nombreArchivo
                            ]);

                            Log::info("Guía $numeroGuia actualizada sin cambios críticos");
                        }
                    } else {
                        // Crear nueva guía
                        Guia_excel::create($datosExcel);
                        $guiasCreadas++;
                        Log::info("Guía $numeroGuia CREADA");
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
    /*================================================================================================= */
    // Detecta si hubo cambios críticos que requieran sincronización
    private function detectarCambiosEnGuia($guiaExistente, $datosExcel)
    {
        // 1. Cambio de estado
        if ($guiaExistente->estado !== $datosExcel['estado']) {
            Log::info("Cambio de estado detectado: {$guiaExistente->estado} → {$datosExcel['estado']}");
            return true;
        }

        // 2. Cambio en fecha de consulta (índica actividad reciente en la guía)
        if ($datosExcel['fecha_consulta']) {
            $fechaExistente = $guiaExistente->fecha_consulta ? $guiaExistente->fecha_consulta->format('Y-m-d H:i:s') : null;
            $fechaNueva = $datosExcel['fecha_consulta']->format('Y-m-d H:i:s');

            if ($fechaExistente !== $fechaNueva) {
                Log::info("Cambio en fecha de consulta detectado: $fechaExistente → $fechaNueva");
                return true;
            }
        }

        // 3. Progreso de estado (pendiente -> en_proceso -> terminado)
        if ($this->esProgresoDeEstado($guiaExistente->estado, $datosExcel['estado'])) {
            Log::info("Progreso de estado detectado: {$guiaExistente->estado} → {$datosExcel['estado']}");
            return true;
        }

        return false;
    }
    /*================================================================================================= */
    // Verifica si el cambio de estado representa un progreso lógico
    private function esProgresoDeEstado($estadoAnterior, $estadoNuevo)
    {
        $progresosValidos = [
            'pendiente' => ['en_proceso', 'terminado', 'error', 'cancelado'],
            'en_proceso' => ['terminado', 'error', 'cancelado']
        ];

        return isset($progresosValidos[$estadoAnterior]) &&
            in_array($estadoNuevo, $progresosValidos[$estadoAnterior]);
    }

    // Actualiza guía y registra la sincronización
    private function actualizarGuiaConSincronizacion($guia, $datosExcel)
    {
        $estadoAnterior = $guia->estado;

        // Actualizar fecha de sincronizacion cuando hay cambios críticos
        $datosExcel['fecha_ultima_sincronización'] = now();

        // Registrar sincronización si hubo cambio de estado
        if ($estadoAnterior !== $datosExcel['estado']) {
            $datosExcel['observaciones'] = "sincronización automática desde Excel: {$estadoAnterior} → {$datosExcel['estado']}";

            Log::info("Sincronizando guía {$guia->numero_guia}: {$estadoAnterior} → {$datosExcel['estado']}");
        } else {
            $datosExcel['observaciones'] = "sincronización automática - datos actualizados";

            Log::info("Sincronizando guía {$guia->numero_guia}: datos actualizados");
        }

        $guia->update($datosExcel);
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

            // Solo mostrar informacion de progreso
            $ultimoCambio = $guia->obtenerUltimoCambioEstado();
            $puedeProgresar = $guia->puedeSerSincronizada();

            return response()->json([
                'success' => true,
                'message' => 'Información de sincronización',
                'data' => [
                    'id' => $guia->id,
                    'numero_guia' => $guia->numero_guia,
                    'estado_actual' => $guia->estado,
                    'fecha_ultima_sincronización' => $guia->fecha_ultima_sincronización ?
                        $guia->fecha_ultima_sincronización->format('d-m-Y H:i') : 'Nunca',
                    'ultimo_cambio' => $ultimoCambio,
                    'puede_progresar' => $puedeProgresar,
                    'info' => $puedeProgresar ?
                        'Suba un Excel actualizado para sincronizar automáticamente' :
                        'La guía está en estado final'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error al consultar información de guía: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar información de la guía'
            ], 500);
        }
    }
    /* ================================================================================================= */
    // Sincronización masiva( cambio de estado por lotes)
    public function sincronizarMasiva(Request $request)
    {
        try {
            //Solo generar reporte del estado actual
            $estadisticas = [
                'pendientes' => Guia_excel::where('estado', 'pendiente')->count(),
                'en_proceso' => Guia_excel::where('estado', 'en_proceso')->count(),
                'terminadas' => Guia_excel::where('estado', 'terminado')->count(),
                'canceladas' => Guia_excel::where('estado', 'cancelado')->count(),
                'con_error' => Guia_excel::where('estado', 'error')->count(),
                'total' => Guia_excel::count()
            ];

            $guiasSincronizadasHoy = Guia_excel::whereDate('fecha_ultima_sincronización', today())->count();

            //ERROR si no hay guías
            if ($estadisticas['total'] === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'No hay guías en el sistema',
                    'data' => [
                        'estadisticas' => $estadisticas,
                        'sincronizadas_hoy' => 0,
                        'guias_actualizadas' => [],
                        'info' => 'Sube un archivo Excel para comenzar'
                    ]
                ]);
            }

            // Obtener todas las guías actualizadas
            $todasLasGuias = Guia_excel::orderBy('created_at', 'desc')
                ->get()
                ->map(function ($guia) {
                try {
                    return $this->formatGuiaParaFrontend($guia);
                    } catch (\Exception $e) {
                        \Log::error("Error formateando guía {$guia->id}: " . $e->getMessage());
                        return null;
                    }
                })
                ->filter(); // Eliminar nulls

            return response()->json([
                'success' => true,
                'message' => 'Reporte de estado actual',
                'data' => [
                    'estadisticas' => $estadisticas,
                    'sincronizadas_hoy' => $guiasSincronizadasHoy,
                    'guias_actualizadas' => $todasLasGuias,
                    'info' => 'Para sincronizar, sube un Excel actualizado con nuevos estados'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR GENERANDO REPORTE: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /* ================================================================================================= */
    // Métodos auxiliares privados para definir palabras segun estados del excel
    private function normalizarEstado($estado)
    {
        $estado = strtolower(trim($estado));

        $mapeo = [
            // Estados principales
            'pendiente' => 'pendiente',
            'terminado' => 'terminado',
            'cancelado' => 'cancelado',
            'error' => 'error',

            // Mapeo de "En tránsito" -> "En proceso"
            'en tránsito' => 'en_proceso',
            'en transito' => 'en_proceso',
            'transito' => 'en_proceso',
            'tránsito' => 'en_proceso',
            'en proceso' => 'en_proceso',
            'en_proceso' => 'en_proceso',
            'enproceso' => 'en_proceso',
            'proceso' => 'en_proceso',
            'enviado' => 'en_proceso',
            'despachado' => 'en_proceso',
            'ruta' => 'en_proceso',
            'en ruta' => 'en_proceso',

            // Mapeo de "Entregado" -> "Terminado"
            'entregado' => 'terminado',
            'entregada' => 'terminado',
            'finalizado' => 'terminado',
            'completado' => 'terminado',
            'exitoso' => 'terminado',
            'recibido' => 'terminado',

            // Mapeo de Errores
            'fallido' => 'error',
            'fallo' => 'error',
            'no entregado' => 'error',
            'devuelto' => 'error',
            'devolucion' => 'error',
            'rechazado' => 'error',

            // Mapeo de Cancelados -> "Cancelado"
            'anulado' => 'cancelado',
            'cancelada' => 'cancelado',
            'suspendido' => 'cancelado'
        ];

        $estadoMapeado = $mapeo[$estado] ?? 'pendiente';

        Log::info("Normalizando estado: '$estado' → '$estadoMapeado'");

        return $estadoMapeado;
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
}

