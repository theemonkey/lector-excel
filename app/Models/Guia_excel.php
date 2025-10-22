<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\GuiaExcelFactory;

class Guia_excel extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return GuiaExcelFactory::new();
    }

    protected $table = 'guias_excel';

    protected $fillable = [
        'numero_guia',
        'referencia',
        'destinatario',
        'ciudad',
        'direccion',
        'estado',
        'fecha_consulta',
        'fecha_ultima_sincronización',
        'historial_estados',
        'observaciones',
        'archivo_origen'
    ];

    protected $casts = [
        'fecha_consulta' => 'datetime',
        'fecha_ultima_sincronización' => 'datetime',
        'historial_estados' => 'array'
    ];

    // Scopes para filtros comunes
    public function scopePorEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopeEnProceso($query)
    {
        return $query->where('estado', 'en_proceso');
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeEnTransito($query)
    {
        return $query->where('estado', 'en_proceso');
    }

    public function scopeTerminadas($query)
    {
        return $query->whereIn('estado', ['terminado', 'error', 'cancelado']);
    }

    // Mutator para formatear fechas al obtener
    public function setEstadoAttribute($value)
    {
        $estadoAnterior = $this->attributes['estado'] ?? null;
        $nuevoEstado = strtolower($value);

        // Actualizar historial de estados si el estado realmente cambió
        if ($estadoAnterior !== $nuevoEstado) {
            $historial = $this->historial_estados ?? [];
            $historial[] = [
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $nuevoEstado,
                'fecha' => now()->toISOString(),
                'timestamp' => time(),
                'accion' => 'cambio_estado'
            ];
            $this->attributes['historial_estados'] = json_encode($historial);
        }
        $this->attributes['estado'] = $nuevoEstado;
    }

    // Accessor para obtener el estado formateado
    public function getEstadoFormateadoAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->estado));
    }

    public function getFechaConsultaFormateadaAttribute()
    {
        return $this->fecha_consulta ? $this->fecha_consulta->format('d/m/Y H:i:s') : 'Nunca';
    }

    // Metodos de utilidad
    public function puedeSerSincronizada()
    {
        // Solo se pueden sincronizar guías en estado 'pendiente' o 'en_proceso'
        return in_array($this->estado, ['pendiente', 'en_proceso']);
    }
    /*======================================================================================== */
    // Método para obtener siguiente estado lógico
    public function obtenerSiguienteEstado()
    {
        switch ($this->estado) {
            case 'pendiente':
                // Pendiente puede ir a en_proceso o directamente a terminado
                return ['en_proceso', 'terminado', 'error'];

            case 'en_proceso':
                // En proceso solo puede ir a estados finales
                return ['terminado', 'error', 'cancelado'];

            default:
                // Estados finales no tienen siguiente estado
                return [$this->estado];
        }
    }
    /* =================================================================================== */
    public function marcarComoSincronizada($nuevoEstado, $observaciones = null)
    {
        $this->update([
            'estado' => $nuevoEstado,
            'fecha_ultima_sincronización' => now(),
            'fecha_consulta' => now(),
            'observaciones' => $observaciones
        ]);
    }

    public function obtenerUltimoCambioEstado()
    {
        if (!$this->historial_estados || empty($this->historial_estados)) {
            return null;
        }

        $historial = $this->historial_estados;
        $ultimoCambio = end($historial);

        return [
            'fecha' => $ultimoCambio['fecha'] ?? null,
            'estado_anterior' => $ultimoCambio['estado_anterior'] ?? null,
            'estado_nuevo' => $ultimoCambio['estado_nuevo'] ?? $this->estado,
            'accion' => $ultimoCambio['accion'] ?? 'cambio_estado'
        ];
    }
}
