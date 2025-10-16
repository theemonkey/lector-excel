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
        'fecha_ultima_sincronizacion',
        'historial_estados',
        'observaciones',
        'archivo_origen'
    ];

    protected $casts = [
        'fecha_consulta' => 'datetime',
        'fecha_ultima_sincronizacion' => 'datetime',
        'historial_estados' => 'array'
    ];

    // Scopes para filtros comunes
    public function scopePorEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopeEnProceso($query)
    {
        return $query->whereIn('estado', ['pendiente', 'en_proceso']);
    }

    public function scopeTerminadas($query)
    {
        return $query->whereIn('estado', ['terminado', 'error', 'cancelado']);
    }

    // Mutator para formatear fechas al obtener
    public function setEstadoAttribute($value)
    {
        $this->attributes['estado'] = strtolower($value);

        // Actualizar historial de estados
        $historial = $this->historial_estados ?? [];
        $historial[] = [
            'estado' => strtolower($value),
            'fecha' => now(),
            'timestamp' => time()
        ];
        $this->attributes['historial_estados'] = json_encode($historial);
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
        return in_array($this->estado, ['pendiente', 'en_proceso']);
    }

    public function marcarComoSincronizada($nuevoEstado, $observaciones = null)
    {
        $this->update([
            'estado' => $nuevoEstado,
            'fecha_ultima_sincronización' => now(),
            'observaciones' => $observaciones
        ]);
    }

    public function obtenerUltimoCambioEstado()
    {
        $historial = $this->historial_estados ?? [];
        return $historial ? end($historial) : null;
    }
}
