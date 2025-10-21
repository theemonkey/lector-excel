$(document).ready(function () {
    let guidesDataTable; // Variable global para la DataTable

    // Inicializar DataTable
    guidesDataTable = $('#guidesTable').DataTable({
        "language": {
            "url": "https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" // Traducción al español
        },
        "responsive": true,
        "ordering": false,   // Ordenamiento desactivado
        "columns": [
            { "data": "numeroGuia" },
            { "data": "referencia" },
            { "data": "destinatario" },
            { "data": "ciudad" },
            { "data": "direccion" },
            {
                "data": "estado", "render": function (data, type, row) {
                    return formatEstadoBadge(data);
                }
            },
            { "data": "fechaConsulta", "defaultContent": "N/A" },
            {
                "data": null,
                "render": function (data, type, row) {
                    return createActionButton(row.id, row.numeroGuia);
                }
            }
        ],
        "createdRow": function (row, data, dataIndex) {
            // Deshabilitar botón de sincronizar si el estado es "Terminado"
            const estadosDisabled = ['terminado', 'error'];
            if (estadosDisabled.includes(data.estado.toLowerCase())) {
                $(row).find('.sync-guide-btn').prop('disabled', true).attr('title', 'Guía terminada o con error, no se puede sincronizar.');
            }
        }
    });

    // Función para procesar el archivo Excel
    $('#uploadExcelBtn').on('click', function () {
        const fileInput = $('#excelFile')[0];
        const file = fileInput.files[0];

        if (!validateFile(file)) return;

        processExcelFile(file);
    });

    // Validar el archivo seleccionado
    function validateFile(file) {
        const uploadStatus = $('#uploadStatus');

        if (!file) {
            showError(uploadStatus, 'Por favor, selecciona un archivo Excel.');
            return false;
        }

        // Validación de extensiones
        const validExtensions = ['xlsx', 'xls'];
        const fileExtension = file.name.split('.').pop().toLowerCase();

        if (!validExtensions.includes(fileExtension)) {
            showError(uploadStatus, 'Solo se permiten archivos .xls o .xlsx');
            return false;
        }

        // Validación adicional de tamaño de archivo (opcional)
        const maxSize = 10 * 1024 * 1024; // 10MB
        if (file.size > maxSize) {
            showError(uploadStatus, 'El archivo es demasiado grande. Máximo 10MB.');
            return false;
        }

        return true;
    }
    /* ====================FUNCIONES DE PROCESAMIENTO DE ARCHIVO EXCEL ===================================== */
    function processExcelFile(file) {
        const loadingUpload = $('#loadingUpload');
        const uploadStatus = $('#uploadStatus');
        const syncAllBtn = $('#syncAllBtn');

        loadingUpload.show(); // Mostrar spinner
        uploadStatus.empty();
        guidesDataTable.clear().draw(); // Limpiar tabla antes de cargar nuevos datos
        syncAllBtn.prop('disabled', true); // Deshabilitar botón de sincronización masiva

        // Enviar el archivo a leer al Backend usando FormData (opcional, si se desea procesar en backend)
        const formData = new FormData();
        formData.append('archivo', file);

        $.ajax({
            url: '/guias/procesar-excel', // Ruta real del backend
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') // Laravel u otro framework con CSRF
            },
            success: function (response) {
                // Manejar la respuesta del backend
                console.log("Archivo enviado al backend exitosamente:", response);

                if (response.success) {
                    // Mostrar datos en la tabla
                    displayGuiasInTable(response.data.guias);

                    // Mostrar notificación de éxito
                    showNotification(
                        `Excel procesado: ${response.data.guias_creadas} creadas, ${response.data.guias_actualizadas} actualizadas`,
                        'success'
                    );

                    // Habilitar botón de sincronización masiva
                    syncAllBtn.prop('disabled', false);

                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            },
            error: function (xhr, status, error) {
                console.error("Error al enviar el archivo al backend:", error);
                console.error('Respuesta del servidor:', xhr.responseText);
                showNotification('Error al procesar el archivo en el servidor.', 'error');
            },
            complete: function () {
                loadingUpload.hide(); // Ocultar spinner
            }
        });
    }

    // Mostrar guias en la tabla desde respuesta del backend
    function displayGuiasInTable(guias) {
        console.log("Intentando mostrar guías:", guias);

        //Guardar página actual
        const currentPage = guidesDataTable.page();

        // Limpiar tabla antes de agregar nuevas filas
        guidesDataTable.clear();

        if (!guias || guias.length === 0) {
            console.log("No hay guías para mostrar");
            guidesDataTable.draw();
            return;
        }

        guias.forEach(function (guia) {
            const row = {
                numeroGuia: guia.numero_guia || 'N/A',
                referencia: guia.referencia || 'N/A',
                destinatario: guia.destinatario || 'N/A',
                ciudad: guia.ciudad || 'N/A',
                direccion: guia.direccion || 'N/A',
                estado: guia.estado || 'pendiente',
                fechaConsulta: guia.fecha_consulta_formateada || 'Nunca',
                id: guia.id,
                numero_guia: guia.numero_guia
            };

            console.log("Fila a agregar:", row);
            guidesDataTable.row.add(row);
        });
        // Redibujar tabla
        guidesDataTable.draw();

        // Mantener página actual si es posible
        try {
            if (currentPage < guidesDataTable.page.info().pages) {
                guidesDataTable.page(currentPage).draw('page');
            }
        } catch (e) {
            console.error("Error al mantener la página actual:", e);
        }
        console.log("Tabla actualizada manteniendo paginación");
    }

    // Crear botón de acción con ID de la base de datos
    function createActionButton(guiaId, numeroGuia) {
        return `
            <button class="btn btn-sm btn-primary sync-guide-btn"
            data-id="${guiaId}"
            data-numero="${numeroGuia}"
            title="Sincronizar guía ${numeroGuia}">
                <i class="fas fa-sync-alt"></i>
            </button>`;
    }

    // ======>>> Sincronización individual conectada al backend <<<======
    $(document).on('click', '.sync-guide-btn', function () {
        const button = $(this);
        const guiaId = button.data('id');
        const numeroGuia = button.data('numero');

        // Deshabilitar botón durante sincronización
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: `/guias/${guiaId}/sincronizar`,
            type: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function (response) {
                if (response.success) {
                    // Mostrar información de sincronización
                    const info = response.data;
                    showNotification(`Guía ${numeroGuia}: ${info.estado_actual.toUpperCase()} |
                    última sincronización: ${info.fecha_ultima_sincronizacion} |
                    ${info.info}`,
                        info.puede_progresar ? 'info' : 'warning'
                    );
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            },
            error: function (xhr, status, error) {
                console.error('Error sincronizando guía:', error);
                showNotification(`Error al sincronizar guía ${numeroGuia}`, 'error');
            },
            complete: function () {
                // Restaurar el botón
                button.prop('disabled', false).html('<i class="fas fa-sync-alt"></i>');
            }
        });
    });
    /* ========= */
    // =====>>> Sincronización masiva conectada al backend <<<<=====
    // Sincronización masiva conectada al backend
    $('#syncAllBtn').on('click', function () {
        const button = $(this);

        // Usar modal personalizado en lugar de confirm() nativo
        showConfirmationModal(
            '¿Sincronizar todas las guías en proceso?',
            function () {
                // Función que se ejecuta al presionar "Aceptar"
                performMassSync(button);
            }
        );
    });
    /* ========= */

    // Realizar sincronización masiva - Solo mostrar reporte
    function performMassSync(button) {
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sincronizando...');

        $.ajax({
            url: '/guias/sincronizar-masiva',
            type: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function (response) {
                console.log('Respuesta completa del servidor:', response);

                if (response.success) {
                    const stats = response.data.estadisticas;
                    const mensaje = `REPORTE DE ESTADO:
                        Pendientes: ${stats.pendientes} | En Proceso: ${stats.en_proceso} | 
                        Terminadas: ${stats.terminadas} | Con Error: ${stats.con_error} |
                        Sincronizadas hoy: ${response.data.sincronizadas_hoy}`;

                    showNotification(mensaje, 'info');

                    // Actualizar tabla
                    if (response.data.guias_actualizadas) {
                        displayGuiasInTable(response.data.guias_actualizadas);
                    }
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            },
            error: function (xhr, status, error) {
                console.error('Error en sincronización masiva:', error);
                console.error('Respuesta completa:', xhr.responseText);
                showNotification('Error al generar reporte.', 'error');
            },
            complete: function () {
                button.prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Ver Reporte de Estado');
            }
        });
    }

    // Modal de confirmación personalizado
    function showConfirmationModal(message, onConfirm, onCancel = null) {
        // Crear modal HTML
        const modalHtml = `
        <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header border-0">
                        <h5 class="modal-title fw-bold text-primary" id="confirmationModalLabel">
                            <i class="fas fa-question-circle me-2"></i>Confirmación
                        </h5>
                    </div>
                    <div class="modal-body text-center py-4">
                        <p class="mb-0 fs-6">${message}</p>
                    </div>
                    <div class="modal-footer border-0 justify-content-center">
                        <button type="button" class="btn btn-primary px-4" id="confirmBtn">
                            <i class="fas fa-check me-1"></i>Aceptar
                        </button>
                        <button type="button" class="btn btn-outline-secondary px-4" id="cancelBtn" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

        // Remover modal anterior si existe
        $('#confirmationModal').remove();

        // Agregar modal al body
        $('body').append(modalHtml);

        // Configurar eventos
        $('#confirmBtn').on('click', function () {
            $('#confirmationModal').modal('hide');
            if (onConfirm) onConfirm();
        });

        $('#cancelBtn').on('click', function () {
            $('#confirmationModal').modal('hide');
            if (onCancel) onCancel();
        });

        // Mostrar modal
        $('#confirmationModal').modal('show');

        // Limpiar modal después de cerrarse
        $('#confirmationModal').on('hidden.bs.modal', function () {
            $(this).remove();
        });
    }

    /* =================================================================================================== */
    // Recargar datos de la tabla desde el backend
    function reloadTableData() {
        $.ajax({
            url: '/guias',
            type: 'GET',
            success: function (response) {
                if (response.success) {
                    displayGuiasInTable(response.data);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error recargando datos:', error);
            }
        });
    }

    // Estados
    function formatEstadoBadge(estado) {
        const badgeClasses = {
            'pendiente': 'status-pendiente',
            'en_proceso': 'status-en-proceso',
            'terminado': 'status-terminado',
            'error': 'status-error'
        };

        const estadoNormalizado = estado.toLowerCase().replace(' ', '_');
        const badgeClass = badgeClasses[estadoNormalizado] || 'bg-secondary text-white';

        // Mostrar nombres en Frontend amigables
        const nombresAmigables = {
            'pendiente': 'PENDIENTE',
            'en_proceso': 'EN PROCESO',
            'terminado': 'TERMINADO',
            'error': 'ERROR'
        };

        const nombreAmigable = nombresAmigables[estadoNormalizado] || estado.toUpperCase();

        return `<span class="status-badge ${badgeClass}">${nombreAmigable}</span>`;
    }

    /* =================================================================================================== */
    // Función para mostrar notificaciones luego de la sincronizacion(masiva-individual)
    function showNotification(message, type = 'info') {
        console.log(`Notificación (${type}): ${message}`);

        // Mapear tipos a clases de Bootstrap
        const typeClassMap = {
            'info': 'alert-info',
            'success': 'alert-success',
            'warning': 'alert-warning',
            'error': 'alert-danger'
        };

        const alertClass = typeClassMap[type] || 'alert-info';

        // Crear y mostrar la alerta
        const notification = $(`<div class="alert ${alertClass} alert-dismissible fade show position-fixed"
            style="top: 20px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); ">
            <strong>${type === 'error' ? 'Error:' : type === 'success' ? '' : 'Info:'}</strong> ${message}
        </div>`);

        $('body').append(notification);

        // Desaparecer después de 3 segundos
        setTimeout(() => {
            notification.fadeOut(500, () => notification.remove());
        }, 3000);
    }

    /* =================================================================================================== */
    // DROPDOWN FILTRO DE ESTADOS
    /* =================================================================================================== */

    // Variables para el filtro
    let activeFilters = [];

    // Inicializar eventos del dropdown
    function initializeDropdownFilter() {
        // Manejar cambios en los checkboxes
        $('.estado-filter').on('change', function () {
            updateActiveFilters();
            applyEstadoFilter();
            updateFilterLabel();
        });

        // Botón "Seleccionar Todos"
        $('#selectAllEstados').on('click', function () {
            $('.estado-filter').prop('checked', true);
            updateActiveFilters();
            applyEstadoFilter();
            updateFilterLabel();
        });

        // Botón "Limpiar Filtros"
        $('#clearEstadoFilter').on('click', function () {
            $('.estado-filter').prop('checked', false);
            activeFilters = [];
            applyEstadoFilter();
            updateFilterLabel();
        });

        // Prevenir que el dropdown se cierre al hacer click en los checkboxes
        $('.dropdown-menu').on('click', function (e) {
            e.stopPropagation();
        });
    }

    // Actualizar array de filtros activos
    function updateActiveFilters() {
        activeFilters = [];
        $('.estado-filter:checked').each(function () {
            activeFilters.push($(this).val().toLowerCase());
        });
    }

    // Aplicar filtro a la DataTable
    function applyEstadoFilter() {
        if (activeFilters.length === 0) {
            // Si no hay filtros, mostrar todas las filas
            guidesDataTable.column(5).search('').draw();
        } else {
            // Crear expresión regular para el filtro
            const filterRegex = activeFilters.join('|');
            guidesDataTable.column(5).search(filterRegex, true, false).draw();
        }

        // Actualizar contador de resultados
        updateResultCounter();
    }

    // Actualizar el texto del label del filtro
    function updateFilterLabel() {
        const totalFilters = $('.estado-filter').length;
        const checkedFilters = $('.estado-filter:checked').length;
        const filterLabelText = $('#filterLabelText');

        if (checkedFilters === 0) {
            filterLabelText.text('ULTIMO ESTADO');
            $('#estadoFilterDropdown').removeClass('btn-warning').addClass('btn-outline-light');
        } else if (checkedFilters === totalFilters) {
            filterLabelText.text('ULTIMO ESTADO (TODOS)');
            $('#estadoFilterDropdown').removeClass('btn-warning').addClass('btn-outline-light');
        } else {
            filterLabelText.text(`ULTIMO ESTADO (${checkedFilters})`);
            $('#estadoFilterDropdown').removeClass('btn-outline-light').addClass('btn-warning');
        }
    }

    // Actualizar contador de resultados (opcional)
    function updateResultCounter() {
        const info = guidesDataTable.page.info();
        if (info.recordsDisplay !== info.recordsTotal) {
            console.log(`Mostrando ${info.recordsDisplay} de ${info.recordsTotal} guías`);
        }
    }

    // Función para resetear filtros cuando se carga nueva data
    function resetFilters() {
        $('.estado-filter').prop('checked', false);
        activeFilters = [];
        updateFilterLabel();
    }

    // Función personalizada de filtro para DataTables
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        // Solo aplicar este filtro a nuestra tabla específica
        if (settings.nTable.id !== 'guidesTable') {
            return true;
        }

        // Si no hay filtros activos, mostrar todo
        if (activeFilters.length === 0) {
            return true;
        }

        // Obtener el estado de la fila (columna 5, índice 5)
        const estadoCell = data[5] || '';

        // Extraer solo el texto del estado (sin HTML)
        const tempDiv = $('<div>').html(estadoCell);
        const estadoTexto = tempDiv.text().toLowerCase().trim();

        // Verificar si el estado está en los filtros activos
        return activeFilters.includes(estadoTexto);
    });

    // Inicializar el dropdown cuando la página esté lista
    $(document).ready(function () {
        // Inicializar después de que DataTable esté listo
        setTimeout(function () {
            initializeDropdownFilter();
        }, 500);
    });

    // Test de conectividad al backend
    $.ajax({
        url: '/guias',
        type: 'GET',
        success: function (response) {
            console.log('Backend conectado:', response);
        },
        error: function (xhr, status, error) {
            console.error('Error conectando backend:', xhr.status, xhr.responseText);
        }
    });
});
