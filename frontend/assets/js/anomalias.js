let TODOS_LOS_EQUIPOS = [];
let equipoSeleccionado = null;

async function init() {
  await cargarListaEquipos();
  configurarBuscador();
  aplicarFiltroDesdeUrl();
  document.getElementById('filtroTipo').addEventListener('change', cargarAnomalias);
  document.getElementById('filtroDias').addEventListener('change', cargarAnomalias);
  document.getElementById('btnFiltrar').addEventListener('click', cargarAnomalias);
  document.getElementById('btnLimpiar').addEventListener('click', limpiarFiltros);
  cargarAnomalias();
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
  const tipo = document.getElementById('filtroTipo').value;
  const dias = document.getElementById('filtroDias').value;

  const params = new URLSearchParams();
  if (equipoId) params.set('equipo', equipoId);
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
  const filtroTexto = equipoSeleccionado ? `equipo ${equipoSeleccionado.nombre}` : 'toda la flota';
  document.getElementById('resumenResultados').textContent =
    `${data.total} anomalía(s) — ${filtroTexto}, últimos ${document.getElementById('filtroDias').value} día(s)`;

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
    </tr>
  `).join('');

  $tabla.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th></tr>
      ${filas}
    </table>
  `;
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