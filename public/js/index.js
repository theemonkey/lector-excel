$(document).ready(function () {
    let guidesDataTable; // Variable global para la DataTable

    // Inicializar DataTable
    guidesDataTable = $('#guidesTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" // Traducción al español
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
            guidesDataTable.row.add(row).draw(false);
        });

        guidesDataTable.draw();
        console.log("Tabla actualizada");
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

    // Sincronización individual conectada al backend
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
                    // Actualizar estado en la tabla
                    updateRowEstado(button, response.data.estado_nuevo);

                    showNotification(`Guía ${numeroGuia} sincronizada: ${response.data.estado_nuevo}`, 'success');
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

    // Actualizar estado en la fila de la tabla
    function updateRowEstado(button, nuevoEstado) {
        const row = guidesDataTable.row(button.closest('tr'));
        const data = row.data();

        // Actualizar columna de estado
        data.estado = nuevoEstado;
        data.fechaConsulta = new Date().toLocaleString('es-ES'); // Actualizar fecha de consulta

        row.data(data).draw();
    }

    // Sincronización masiva conectada al backend
    $('#syncAllBtn').on('click', function () {
        const button = $(this);

        if (confirm('¿Estás seguro de sincronizar todas las guías en proceso?')) {
            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sincronizando...');

            $.ajax({
                url: '/guias/sincronizar-masiva',
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (response) {
                    if (response.success) {
                        showNotification(`Sincronización masiva completada: ${response.data.exitosas} exitosas, ${response.data.fallidas} fallidas`, 'success');

                        // Recargar datos de la tabla
                        reloadTableData();
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Error en sincronización masiva:', error);
                    showNotification('Error en la sincronización masiva.', 'error');
                },
                complete: function () {
                    button.prop('disabled', false).html('<i class="fas fa-sync-alt"></i> Sincronizar Todas las Guías en Proceso');
                }
            });
        }
    });

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

        return `<span class="status-badge ${badgeClass}">${estado.toUpperCase()}</span>`;
    }

    function processExcelData(json) {
        const rawData = json.slice(1); // Datos sin encabezado
        // # de celdas segun el archivo excel a subir (7 columnas: A-G)
        return rawData
            .filter(row => row && row.length > 0 && row[0]) // Filtrar filas vacías
            .map(row => ({
                numeroGuia: row[0] || 'N/A', // Columna A
                referencia: row[1] || 'N/A', // Columna B
                destinatario: row[2] || 'N/A', // Columna C
                ciudad: row[3] || 'N/A', // Columna D
                direccion: row[4] || 'N/A', // Columna E
                estado: row[5] || 'Pendiente', // Columna F (estado inicial del excel)
                fechaConsulta: row[6] ? moment(row[6], 'DD/MM/YYYY HH:mm').format('DD-MM-YYYY HH:mm') : 'Nunca', // Columna G
            }));
    }

    function updateSyncButton(data, syncBtn) {
        const hasGuiaEnProceso = data.some(g =>
            ['en proceso', 'pendiente'].includes(g.estado.toLowerCase())
        );
        syncBtn.prop('disabled', !hasGuiaEnProceso);
    }

    function showError(container, message) {
        const alertElement = $(`<div class="alert alert-danger alert-dismissible mt-2" role="alert">${message}
    </div>`);

        container.html(alertElement);
        //Oculto después de 4 seg
        setTimeout(() => {
            alertElement.fadeOut(500, () => alertElement.remove());
        }, 4000); // Desaparece después de 4 segundos
    }

    function showSuccess(container, message) {
        const alertElement = $(`<div class="alert alert-success alert-dismissible mt-2" role="alert">${message}
    </div>`);

        container.html(alertElement);
        //Oculto después de 3 seg
        setTimeout(() => {
            alertElement.fadeOut(500, () => alertElement.remove());
        }, 3000); // Desaparece después de 3 segundos
    }

    /* =================================================================================================== */
    // Lógica de sincronización individual (delegación de eventos para botones dinámicos)
    $('#guidesTable tbody').on('click', '.sync-guide-btn', function () {
        const button = $(this);
        const rowData = guidesDataTable.row(button.parents('tr')).data();

        if (rowData.estado.toLowerCase() === 'terminado' || rowData.estado.toLowerCase() === 'error') {
            showNotification('Esta guía ya está terminada o con error y no se puede sincronizar.', 'info');
            return;
        }

        button.prop('disabled', true).addClass('loading');

        simulateSync(rowData.numeroGuia).then(newStatus => {
            rowData.estado = newStatus;
            rowData.fechaConsulta = moment().format('DD-MM-YYYY HH:mm');
            guidesDataTable.row(button.parents('tr')).data(rowData).invalidate().draw(false);
            showNotification(`Guía ${rowData.numeroGuia} sincronizada. Nuevo estado: ${newStatus}`, 'success');

            if (newStatus.toLowerCase() === 'terminado' || newStatus.toLowerCase() === 'error') {
                button.prop('disabled', true).attr('title', 'Guía terminada o con error, no se puede sincronizar.');
            } else {
                button.prop('disabled', false);
            }
        }).catch(error => {
            console.error("Error sincronizando guía:", rowData.numeroGuia, error);
            showNotification(`Error al sincronizar guía ${rowData.numeroGuia}.`, 'error');
        }).finally(() => {
            button.removeClass('loading');
        });
    });

    // Funciones de sincronización y utilidad
    $('#syncAllBtn').on('click', function () {
        const syncAllButton = $(this);
        const syncLoading = $('#syncLoading');
        syncAllButton.prop('disabled', true);
        syncLoading.show();

        const guiasEnProceso = guidesDataTable.rows().indexes().filter(function (idx) {
            const data = guidesDataTable.row(idx).data();
            return data.estado.toLowerCase() === 'en proceso' || data.estado.toLowerCase() === 'pendiente';
        });

        if (guiasEnProceso.length === 0) {
            showNotification('No hay guías en proceso para sincronizar.', 'info');
            syncAllButton.prop('disabled', false);
            syncLoading.hide();
            return;
        }

        let syncPromises = [];
        guiasEnProceso.each(function (index) {
            const rowData = guidesDataTable.row(index).data();
            syncPromises.push(
                new Promise(resolve => setTimeout(resolve, index * 100))
                    .then(() => simulateSync(rowData.numeroGuia))
                    .then(newStatus => {
                        rowData.estado = newStatus;
                        rowData.fechaConsulta = moment().format('DD-MM-YYYY HH:mm');
                        guidesDataTable.row(index).data(rowData).invalidate();
                        return { id: rowData.numeroGuia, status: 'success' };
                    })
                    .catch(error => {
                        console.error(`Error sincronizando ${rowData.numeroGuia}:`, error);
                        return { id: rowData.numeroGuia, status: 'error' };
                    })
            );
        });

        Promise.allSettled(syncPromises).then(() => {
            guidesDataTable.draw(false);
            showNotification('Sincronización masiva completada. Revisa los estados.', 'success');
        }).finally(() => {
            syncLoading.hide();
            const remainingInProcess = guidesDataTable.rows().data().toArray().some(g =>
                g.estado.toLowerCase() === 'en proceso' || g.estado.toLowerCase() === 'pendiente'
            );
            syncAllButton.prop('disabled', !remainingInProcess);
        });
    });

    // Simulación de llamada a API para sincronizar guía
    function simulateSync(guideId) {
        return new Promise(resolve => {
            setTimeout(() => {
                const random = Math.random();
                let newStatus;
                if (random < 0.2) {
                    newStatus = 'Terminado';  // 20% de probabilidad
                } else if (random < 0.8) {
                    newStatus = 'En proceso';  // 60% de probabilidad
                } else {
                    newStatus = 'Error';  // 20% de probabilidad de error
                }
                resolve(newStatus);
            }, 1500);
        });
    }
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
