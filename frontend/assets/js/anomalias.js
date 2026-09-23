let TODOS_LOS_EQUIPOS = [];
let equipoSeleccionado = null;

async function init() {
  await cargarListaEquipos();
  poblarFiltroClientes();
  configurarBuscador();
  aplicarFiltroDesdeUrl();
  document.getElementById('filtroCliente').addEventListener('change', cargarAnomalias);
  document.getElementById('filtroTipo').addEventListener('change', cargarAnomalias);
  document.getElementById('filtroDias').addEventListener('change', cargarAnomalias);
  document.getElementById('btnFiltrar').addEventListener('click', cargarAnomalias);
  document.getElementById('btnLimpiar').addEventListener('click', limpiarFiltros);
  cargarAnomalias();
}

/**
 * Arma el desplegable de "Flota / Cliente" con los clientes reales que ya
 * tenemos en la flota (sacados de los equipos ya cargados, sin pedir nada
 * nuevo al backend) — pedido de Emiliano: poder ver cuántas anomalías
 * tiene una flota/cliente entera, no solo un equipo puntual.
 */
function poblarFiltroClientes() {
  const clientes = [...new Set(
    TODOS_LOS_EQUIPOS.map(eq => eq.cliente).filter(c => c && c.trim() !== '')
  )].sort((a, b) => a.localeCompare(b, 'es'));

  const $select = document.getElementById('filtroCliente');
  clientes.forEach(cliente => {
    const opt = document.createElement('option');
    opt.value = cliente;
    opt.textContent = cliente;
    $select.appendChild(opt);
  });
}

function aplicarFiltroDesdeUrl() {
  const params = new URLSearchParams(window.location.search);
  const equipoId = params.get('equipo');
  if (!equipoId) return;

  const eq = TODOS_LOS_EQUIPOS.find(e => e.id === equipoId);
  if (eq) {
    equipoSeleccionado = { id: eq.id, nombre: eq.nombre };
    document.getElementById('buscarEquipo').value = eq.nombre;
    document.getElementById('equipoIdSeleccionado').value = eq.id;
  }
}

async function cargarListaEquipos() {
  try {
    const resp = await fetch(`${window.PRECISO_CONFIG.API_BASE}/equipos.php`);
    const data = await resp.json();
    if (resp.ok) {
      TODOS_LOS_EQUIPOS = data.equipos;
    }
  } catch (e) {
    // Silencioso si falla la sugerencia
  }
}

function configurarBuscador() {
  const $input = document.getElementById('buscarEquipo');
  const $sugerencias = document.getElementById('sugerenciasEquipo');

  function mostrarSugerencias(q) {
    equipoSeleccionado = null;
    document.getElementById('equipoIdSeleccionado').value = '';

    let matches;
    let encabezado = '';
    if (q === '') {
      matches = TODOS_LOS_EQUIPOS.slice(0, 20);
      encabezado = `<div class="sugerencia-item sugerencia-vacia">Elegí un equipo, o escribí para buscar (${TODOS_LOS_EQUIPOS.length} en la flota)</div>`;
    } else {
      matches = TODOS_LOS_EQUIPOS
        .filter(eq => eq.nombre.toLowerCase().includes(q) || eq.id.toLowerCase().includes(q) || (eq.cliente && eq.cliente.toLowerCase().includes(q)))
        .slice(0, 15);
    }

    if (matches.length === 0) {
      $sugerencias.innerHTML = `<div class="sugerencia-item sugerencia-vacia">Sin coincidencias</div>`;
    } else {
      $sugerencias.innerHTML = encabezado + matches.map(eq => `
        <div class="sugerencia-item" data-id="${escapeHtml(eq.id)}" data-nombre="${escapeHtml(eq.nombre)}">
          <strong>${escapeHtml(eq.nombre)}</strong> <span class="sugerencia-id">· equipo ${escapeHtml(eq.id)}</span>
        </div>
      `).join('');
    }
    $sugerencias.classList.remove('hidden');
  }

  $input.addEventListener('focus', () => {
    mostrarSugerencias($input.value.trim().toLowerCase());
  });

  $input.addEventListener('input', () => {
    const q = $input.value.trim().toLowerCase();
    if (q === '') {
      cargarAnomalias();
    }
    mostrarSugerencias(q);
  });

  $sugerencias.addEventListener('click', (e) => {
    const item = e.target.closest('.sugerencia-item[data-id]');
    if (!item) return;
    equipoSeleccionado = { id: item.dataset.id, nombre: item.dataset.nombre };
    $input.value = item.dataset.nombre;
    document.getElementById('equipoIdSeleccionado').value = item.dataset.id;
    $sugerencias.classList.add('hidden');
    cargarAnomalias();
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.filtro-campo')) {
      $sugerencias.classList.add('hidden');
    }
  });
}

function limpiarFiltros() {
  equipoSeleccionado = null;
  document.getElementById('buscarEquipo').value = '';
  document.getElementById('equipoIdSeleccionado').value = '';
  document.getElementById('filtroCliente').value = '';
  document.getElementById('filtroTipo').value = '';
  document.getElementById('filtroDias').value = '7';
  cargarAnomalias();
}

async function cargarAnomalias() {
  const $loading = document.getElementById('loading');
  const $error = document.getElementById('error');
  const $contenido = document.getElementById('contenido');

  $loading.classList.remove('hidden');
  $error.classList.add('hidden');
  $contenido.classList.add('hidden');

  const equipoId = document.getElementById('equipoIdSeleccionado').value;
  const cliente = document.getElementById('filtroCliente').value;
  const tipo = document.getElementById('filtroTipo').value;
  const dias = document.getElementById('filtroDias').value;

  const params = new URLSearchParams();
  if (equipoId) params.set('equipo', equipoId);
  if (cliente) params.set('cliente', cliente);
  if (tipo) params.set('tipo', tipo);
  params.set('dias', dias);

  try {
    const resp = await fetch(`${window.PRECISO_CONFIG.API_BASE}/anomalias.php?${params.toString()}`);
    const data = await resp.json();
    if (!resp.ok) {
      throw new Error(data.error || `Error HTTP ${resp.status}`);
    }

    document.getElementById('bannerSimulado').classList.toggle('hidden', !data.simulado);
    renderResultados(data);

    $loading.classList.add('hidden');
    $contenido.classList.remove('hidden');
  } catch (e) {
    $loading.classList.add('hidden');
    $error.classList.remove('hidden');
    $error.innerHTML = `<strong>No se pudieron cargar las anomalías:</strong> ${escapeHtml(e.message)}`;
  }
}

function renderResultados(data) {
  // El texto de arriba tiene que decir qué estás mirando: un equipo, una
  // flota entera, o todo. Antes decía "toda la flota" aunque filtraras
  // por cliente, y confundía.
  const cliente = document.getElementById('filtroCliente').value;
  let filtroTexto = 'todas las flotas';
  if (equipoSeleccionado) {
    filtroTexto = `equipo ${equipoSeleccionado.nombre}`;
  } else if (cliente) {
    filtroTexto = `flota ${cliente}`;
  }
  document.getElementById('resumenResultados').textContent =
    `${data.total} anomalía(s) — ${filtroTexto}, últimos ${document.getElementById('filtroDias').value} día(s)`;

  renderPorFlota(data.anomalias, Boolean(cliente || equipoSeleccionado));

  // Agrupar anomalías por tipo, para los KPIs por tipo de abajo.
  const porTipo = {};
  data.anomalias.forEach(a => {
    const t = a.tipo || 'otro';
    if (!porTipo[t]) {
      porTipo[t] = { count: 0, litros: 0 };
    }
    porTipo[t].count++;
    porTipo[t].litros += (Number(a.litros_perdidos) || 0);
  });

  const totalLitros = data.stats ? data.stats.litros_perdidos_totales : 0;
  const equiposAfectados = data.stats ? data.stats.equipos_afectados : 0;

  // Los 4 tipos SIEMPRE se muestran, con 0 si no hay — así el panel se ve
  // igual siempre y solo se van llenando los números, en vez de aparecer y
  // desaparecer tarjetas según haya o no resultados.
  const TIPOS_FIJOS = [
    { key: 'descarga_detenido', label: 'Descarga Detenido' },
    { key: 'caida_instantanea', label: 'Caída Instantánea' },
    { key: 'consumo_anomalo', label: 'Consumo Anómalo' },
    { key: 'agua_combustible', label: 'Agua en Combustible' },
    { key: 'ralenti', label: 'Ralentí' },
    { key: 'variacion_temperatura', label: 'Variación por temperatura' },
    { key: 'desconexion_sensor', label: 'Desconexión de sensor' },
  ];

  // KPIs Generales
  let kpiHtml = `
    <div class="stat card" style="padding: 15px; text-align: center;">
      <div class="label" style="font-size: 13px; color: var(--color-text-muted);">Total Anomalías</div>
      <div class="value ${data.total > 0 ? 'danger' : 'success'}" style="font-size: 24px; font-weight: bold;">${data.total}</div>
    </div>
    <div class="stat card" style="padding: 15px; text-align: center;">
      <div class="label" style="font-size: 13px; color: var(--color-text-muted);">Mermas Totales</div>
      <div class="value ${totalLitros > 0 ? 'danger' : 'success'}" style="font-size: 24px; font-weight: bold;">${totalLitros.toFixed(1)} L</div>
    </div>
    <div class="stat card" style="padding: 15px; text-align: center;">
      <div class="label" style="font-size: 13px; color: var(--color-text-muted);">Vehículos Afectados</div>
      <div class="value" style="font-size: 24px; font-weight: bold;">${equiposAfectados}</div>
    </div>
  `;

  // KPIs por tipo — fijos, siempre los 4, llenos con 0 si no hay.
  TIPOS_FIJOS.forEach(({ key, label }) => {
    const info = porTipo[key] || { count: 0, litros: 0 };
    kpiHtml += `
      <div class="stat card" style="padding: 15px; text-align: center; border-left: 4px solid var(--color-navy);">
        <div class="label" style="font-size: 13px; color: var(--color-text-muted); text-transform: capitalize;">${escapeHtml(label)}</div>
        <div class="value ${info.count > 0 ? 'danger' : 'success'}" style="font-size: 22px; font-weight: bold;">${info.count} <span style="font-size: 13px; font-weight: normal; color: var(--color-text-muted);">(${info.litros.toFixed(1)} L)</span></div>
      </div>
    `;
  });

  const $kpiContainer = document.getElementById('kpiStats');
  $kpiContainer.style.display = "grid";
  $kpiContainer.style.gridTemplateColumns = "repeat(auto-fit, minmax(210px, 1fr))";
  $kpiContainer.style.gap = "15px";
  $kpiContainer.innerHTML = kpiHtml;

  // 3. Renderizar la tabla de anomalías
  const $tabla = document.getElementById('tablaAnomalias');

  if (data.anomalias.length === 0) {
    $tabla.innerHTML = `<div class="empty-state">Sin anomalías con estos filtros. 👍</div>`;
    return;
  }

  const filas = data.anomalias.map(a => `
    <tr>
      <td>${formatearFechaCorta(a.fecha)}</td>
      <td><a href="informe.html?equipo=${encodeURIComponent(a.equipo)}&tipo=semanal">${escapeHtml(a.equipo_nombre)}</a></td>
      <td><span class="tag ${escapeHtml(a.tipo)}">${escapeHtml(a.tipo.replace(/_/g, ' '))}</span></td>
      <td>${escapeHtml(a.detalle)}</td>
      <td>${Number(a.litros_perdidos).toFixed(1)} L</td>
      <td>${renderUbicacion(a.lat, a.lng)}</td>
      <td>${renderLinkGraficoTTM(a.equipo_nombre, a.fecha)}</td>
    </tr>
  `).join('');

  $tabla.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th><th>Gráfico</th></tr>
      ${filas}
    </table>
  `;
}

/**
 * Arma el link al gráfico de sensor de TTM (pedido de Emiliano, 09/09/2026)
 * — para poder cruzar visualmente la anomalía detectada contra los datos
 * crudos del sensor en el sistema de origen. Formato de date_range
 * confirmado con un ejemplo real de Emiliano: "YYYY-MM-DD H:MM / YYYY-MM-DD
 * H:MM" (hora sin cero adelante, minuto con cero adelante), separado por
 * " / ". Ventana: 1 día antes y 1 día después de la anomalía, para tener
 * contexto de sobra sin quedar demasiado ancho.
 */
function linkGraficoTTM(equipoNombre, fechaISO) {
  if (!equipoNombre || !fechaISO) return null;

  const fecha = new Date(fechaISO);
  const desde = new Date(fecha);
  desde.setDate(desde.getDate() - 1);
  desde.setHours(0, 0, 0, 0);
  const hasta = new Date(fecha);
  hasta.setDate(hasta.getDate() + 1);
  hasta.setHours(23, 59, 0, 0);

  const pad = (n) => String(n).padStart(2, '0');
  const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${d.getHours()}:${pad(d.getMinutes())}`;

  const params = new URLSearchParams({
    date_range: `${fmt(desde)} / ${fmt(hasta)}`,
    equipo: equipoNombre,
  });
  return `https://ttm.com.ar/dev/Preciso/reportes.php?${params.toString()}`;
}

function renderLinkGraficoTTM(equipoNombre, fechaISO) {
  const url = linkGraficoTTM(equipoNombre, fechaISO);
  if (!url) return '—';
  return `<a href="${url}" target="_blank" rel="noopener">Ver gráfico ↗</a>`;
}

/**
 * Tabla de anomalías agrupadas por flota/cliente, ordenada de la que más
 * tiene a la que menos. Así de un vistazo se ve qué flota está peor.
 * Cada fila es clickeable: te filtra esa flota entera.
 *
 * Si ya estás mirando una flota o un equipo puntual, la oculto — no tiene
 * sentido un ranking de una sola fila.
 */
function renderPorFlota(anomalias, yaFiltrado) {
  const $card = document.getElementById('cardPorFlota');
  if (yaFiltrado || anomalias.length === 0) {
    $card.classList.add('hidden');
    return;
  }
  $card.classList.remove('hidden');

  const porFlota = {};
  anomalias.forEach(a => {
    const flota = a.equipo_cliente || 'Sin cliente asignado';
    if (!porFlota[flota]) {
      porFlota[flota] = { cantidad: 0, litros: 0, equipos: new Set() };
    }
    porFlota[flota].cantidad++;
    porFlota[flota].litros += Number(a.litros_perdidos) || 0;
    porFlota[flota].equipos.add(a.equipo);
  });

  const filas = Object.entries(porFlota)
    .sort((x, y) => y[1].cantidad - x[1].cantidad)
    .map(([flota, info]) => `
      <tr class="fila-flota" data-flota="${escapeHtml(flota)}" style="cursor:pointer;">
        <td><strong>${escapeHtml(flota)}</strong></td>
        <td>${info.cantidad}</td>
        <td>${info.equipos.size}</td>
        <td>${info.litros.toFixed(1)} L</td>
      </tr>
    `).join('');

  document.getElementById('tablaPorFlota').innerHTML = `
    <table class="anomalias">
      <tr><th>Flota / Cliente</th><th>Anomalías</th><th>Vehículos</th><th>Litros</th></tr>
      ${filas}
    </table>
    <p class="page-subtitle" style="margin:8px 0 0 0;">Tocá una flota para ver solo sus anomalías.</p>
  `;

  document.querySelectorAll('.fila-flota').forEach(fila => {
    fila.addEventListener('click', () => {
      const flota = fila.dataset.flota;
      if (flota === 'Sin cliente asignado') return;
      document.getElementById('filtroCliente').value = flota;
      cargarAnomalias();
    });
  });
}

function renderUbicacion(lat, lng) {
  if (lat === null || lat === undefined || lng === null || lng === undefined) {
    return '—';
  }
  const latTxt = Number(lat).toFixed(5);
  const lngTxt = Number(lng).toFixed(5);
  const url = `https://www.google.com/maps?q=${latTxt},${lngTxt}`;
  return `<a href="${url}" target="_blank" rel="noopener">${latTxt}, ${lngTxt}</a>`;
}

function formatearFechaCorta(iso) {
  const d = new Date(iso);
  return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

init();