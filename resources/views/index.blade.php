@extends('layout/plantilla')

@section('tituloPagina', 'Index Lector Excel')

@section('contenido')

<div class="container">
    <h1 class="mb-3 text-center">
        <i class="fas fa-shipping-fast text-primary me-2"></i>Gestión de Guías de Rastreo
    </h1>
    <p class="text-center text-muted mb-3">Sube un archivo Excel, visualiza las guías y sincroniza su estado de entrega.</p>

    <div class="card mb-5">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-file-excel me-2"></i>1. Cargar Archivo Excel de Guías
        </div>
        <div class="card-body">
            <p class="card-text">Selecciona un archivo Excel (.xls, .xlsx) que contenga la información de las guías de rastreo.</p>
            <div class="mb-3">
                <label for="excelFile" class="form-label">Archivo de Guías</label>
                <input class="form-control" type="file" id="excelFile" accept=".xls,.xlsx" aria-describedby="fileHelp">
                <div id="fileHelp" class="form-text">Solo archivos .xls o .xlsx son permitidos.</div>
            </div>
            <button type="button" class="btn btn-success" id="uploadExcelBtn">
                <i class="fas fa-upload me-2"></i>Procesar Archivo
            </button>
            <div id="loadingUpload" class="mt-3" style="display:none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Procesando...</span>
                </div>
                <span class="ms-2 text-muted">Leyendo y extrayendo datos del Excel, por favor espera...</span>
            </div>
            <div id="uploadStatus" class="mt-3"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-table me-2"></i>2. Guías de Rastreo
        </div>
        <div class="card-body">
            <p class="card-text">La siguiente tabla muestra las guías extraídas del Excel. Puedes sincronizar las guías en proceso.</p>
            <div class="table-responsive">
                <table id="guidesTable" class="table table-striped table-bordered w-100">
                    <thead>
                        <tr>
                            <th>Numero de guia</th>
                            <th>Referencia</th>
                            <th>Destinatario</th>
                            <th>Ciudad</th>
                            <th>Direccion</th>
                            <th>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-light dropdown-toggle border-0 text-white fw-bold" type="button"
                                            id="estadoFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false"
                                            style="background: transparent; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                        <span id="filterLabelText">ULTIMO ESTADO</span>
                                    </button>
                                    <div class="dropdown-menu p-3" aria-labelledby="estadoFilterDropdown" style="min-width: 200px;">
                                        <h6 class="dropdown-header">
                                            <i class="fas fa-filter me-1"></i>FILTRAR POR ESTADO
                                        </h6>
                                        <div class="form-check">
                                            <input class="form-check-input estado-filter" type="checkbox" value="pendiente" id="filter-pendiente">
                                            <label class="form-check-label" for="filter-pendiente">
                                                <span class="status">Pendiente</span>
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input estado-filter" type="checkbox" value="en proceso" id="filter-en-proceso">
                                            <label class="form-check-label" for="filter-en-proceso">
                                                <span class="status">En Proceso</span>
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input estado-filter" type="checkbox" value="terminado" id="filter-terminado">
                                            <label class="form-check-label" for="filter-terminado">
                                                <span class="status">Terminado</span>
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input estado-filter" type="checkbox" value="error" id="filter-error">
                                            <label class="form-check-label" for="filter-error">
                                                <span class="status">Error</span>
                                            </label>
                                        </div>
                                        <hr class="dropdown-divider">
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-primary flex-fill" id="selectAllEstados">
                                                <i class="fas fa-check-double me-1"></i>Todos
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="clearEstadoFilter">
                                                <i class="fas fa-times me-1"></i>Limpiar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th>Fecha Consulta</th>
                            <th class="text-start">
                                <div class="d-flex justify-content-between align-items-center">
                                    Acciones
                                    <button id="deleteSelectedBtn" class="btn btn-xs btn-danger" style="display:none; font-size: 0.75rem; padding: 2px 6px; " title="Eliminar seleccionados">
                                        <i class="fas fa-trash" style="font-size: 0.7rem;"></i>
                                    </button>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        </tbody>
                </table>
            </div>
            <div class="mt-3 text-end">
                <button type="button" class="btn btn-warning" id="syncAllBtn" disabled>
                    <i class="fas fa-sync-alt me-2"></i>Ver Reporte de Estado
                </button>
                <div id="syncLoading" class="mt-2 text-center" style="display:none;">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">Sincronizando...</span>
                    </div>
                    <span class="ms-2 text-muted">Sincronizando guías, por favor espera...</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Cargar dependencias en orden --}}
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>

{{-- ARCHIVO Javascript para manejo de la logica de index.blade --}}
<script src="{{ asset('js/index.js') }}"></script>

@endsection


