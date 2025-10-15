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
            { "data": "idGuia" },
            { "data": "referencia" },
            { "data": "destinatario" },
            { "data": "direccion" },
            {
                "data": "estado", "render": function (data, type, row) {
                    const badgeClasses = {
                        'pendiente': 'status-pendiente',
                        'en proceso': 'status-en-proceso',
                        'terminado': 'status-terminado',
                        'error': 'status-error'
                    };
                    const badgeClass = badgeClasses[data.toLowerCase()] || 'bg-secondary text-white';
                    return `<span class="status-badge ${badgeClass}">${data}</span>`;
                }
            },
            { "data": "fechaConsulta", "defaultContent": "N/A" },
            {
                "data": null, "defaultContent": `
                        <button class="btn btn-sm btn-primary sync-guide-btn" title="Sincronizar guía">
                            <i class="fas fa-sync-alt"></i>
                        </button>`
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

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });

                // Verificar que el archivo tenga hojas
                if (!workbook.SheetNames || workbook.SheetNames.length === 0) {
                    throw new Error('El archivo Excel no contiene hojas válidas');
                }

                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];

                // Convertir la hoja de cálculo a un array de objetos JSON
                const json = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

                // Verificar que hay datos
                if (!json || json.length < 2) {
                    throw new Error('El archivo Excel está vacío o no tiene el formato correcto');
                }

                const processedData = processExcelData(json);

                if (processedData.length === 0) {
                    showError(uploadStatus, 'No se encontraron datos válidos en el archivo Excel.');
                    return;
                }

                guidesDataTable.rows.add(processedData).draw();
                resetFilters();     // Resetear filtros cuando se cargan nuevos datos
                updateSyncButton(processedData, syncAllBtn);
                showSuccess(uploadStatus, `Archivo procesado exitosamente. Se cargaron ${processedData.length} guías.`);

            } catch (error) {
                console.error("Error al procesar el archivo Excel:", error);
                showError(uploadStatus, `Error al procesar el archivo Excel: ${error.message}`);
            } finally {
                loadingUpload.hide(); // Ocultar spinner
            }
        };

        reader.onerror = function () {
            loadingUpload.hide();
            showError($('#uploadStatus'), 'Error al leer el archivo. Intenta nuevamente.');
        };

        reader.readAsArrayBuffer(file);
    }

    function processExcelData(json) {
        const rawData = json.slice(1); // Datos sin encabezado

        return rawData
            .filter(row => row && row.length > 0 && row[0]) // Filtrar filas vacías
            .map(row => ({
                idGuia: row[0] || 'N/A', // Columna A
                referencia: row[1] || 'N/A', // Columna B
                destinatario: row[2] || 'N/A', // Columna C
                direccion: row[3] || 'N/A', // Columna D
                estado: row[4] || 'Pendiente', // Columna E (estado inicial del excel)
                fechaConsulta: row[5] ? moment(row[5], 'DD/MM/YYYY HH:mm').format('DD-MM-YYYY HH:mm') : 'Nunca', // Columna F
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

        simulateSync(rowData.idGuia).then(newStatus => {
            rowData.estado = newStatus;
            rowData.fechaConsulta = moment().format('DD-MM-YYYY HH:mm');
            guidesDataTable.row(button.parents('tr')).data(rowData).invalidate().draw(false);
            showNotification(`Guía ${rowData.idGuia} sincronizada. Nuevo estado: ${newStatus}`, 'success');

            if (newStatus.toLowerCase() === 'terminado' || newStatus.toLowerCase() === 'error') {
                button.prop('disabled', true).attr('title', 'Guía terminada o con error, no se puede sincronizar.');
            } else {
                button.prop('disabled', false);
            }
        }).catch(error => {
            console.error("Error sincronizando guía:", rowData.idGuia, error);
            showNotification(`Error al sincronizar guía ${rowData.idGuia}.`, 'error');
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
                    .then(() => simulateSync(rowData.idGuia))
                    .then(newStatus => {
                        rowData.estado = newStatus;
                        rowData.fechaConsulta = moment().format('DD-MM-YYYY HH:mm');
                        guidesDataTable.row(index).data(rowData).invalidate();
                        return { id: rowData.idGuia, status: 'success' };
                    })
                    .catch(error => {
                        console.error(`Error sincronizando ${rowData.idGuia}:`, error);
                        return { id: rowData.idGuia, status: 'error' };
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
            guidesDataTable.column(4).search('').draw();
        } else {
            // Crear expresión regular para el filtro
            const filterRegex = activeFilters.join('|');
            guidesDataTable.column(4).search(filterRegex, true, false).draw();
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

        // Obtener el estado de la fila (columna 4, índice 4)
        const estadoCell = data[4] || '';

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
});
