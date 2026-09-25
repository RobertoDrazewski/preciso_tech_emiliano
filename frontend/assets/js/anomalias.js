let TODOS_LOS_EQUIPOS = [];
let equipoSeleccionado = null;

async function init() {
  await cargarListaEquipos();
  poblarFiltroClientes();
  configurarBuscador();
  aplicarFiltroDesdeUrl();
  document.getElementById('filtroCliente').addEventListener('change', cargarAnomalias);
  document.getElementById('filtroRevision').addEventListener('change', cargarAnomalias);
  // Cuando se valida o descarta (acá o desde la campanita), recargo todo.
  window.addEventListener('preciso:revision', cargarAnomalias);
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
  // Siempre ofrezco ver los equipos sin cliente, así se puede revisar qué son.
  const sinCliente = document.createElement('option');
  sinCliente.value = '__sin_cliente__';
  sinCliente.textContent = 'Sin cliente asignado';
  $select.appendChild(sinCliente);
  clientes.forEach(cliente => {
    const opt = document.createElement('option');
    opt.value = cliente;
    opt.textContent = cliente;
    $select.appendChild(opt);
  });
}

function aplicarFiltroDesdeUrl() {
  const params = new URLSearchParams(window.location.search);
  const revision = params.get('revision');
  if (revision) document.getElementById('filtroRevision').value = revision;
  const tipoUrl = params.get('tipo');
  if (tipoUrl) document.getElementById('filtroTipo').value = tipoUrl;
  const diasUrl = params.get('dias');
  if (diasUrl && document.querySelector(`#filtroDias option[value="${diasUrl}"]`)) document.getElementById('filtroDias').value = diasUrl;
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
  document.getElementById('filtroRevision').value = 'activas';
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
  const revision = document.getElementById('filtroRevision').value;
  if (revision) params.set('revision', revision);

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

// Cada tipo de anomalía con su ícono y color, para los indicadores de aguja.
const TIPOS_FIJOS = [
  { key: 'descarga_detenido',     label: 'Descarga detenido',        icono: 'fa-gas-pump',            color: '#e03131' },
  { key: 'caida_instantanea',     label: 'Caída instantánea',        icono: 'fa-arrow-trend-down',    color: '#f76707' },
  { key: 'consumo_anomalo',       label: 'Consumo anómalo',          icono: 'fa-chart-line',          color: '#f59f00' },
  { key: 'agua_combustible',      label: 'Agua en combustible',      icono: 'fa-droplet',             color: '#1c7ed6' },
  { key: 'ralenti',               label: 'Ralentí',                  icono: 'fa-hourglass-half',      color: '#868e96' },
  { key: 'variacion_temperatura', label: 'Variación por temperatura', icono: 'fa-temperature-half',   color: '#2f9e44' },
  { key: 'desconexion_sensor',    label: 'Desconexión de sensor',    icono: 'fa-plug-circle-xmark',   color: '#495057' },
  { key: 'corte_alimentacion',    label: 'Corte de alimentación',    icono: 'fa-power-off',           color: '#c2255c' },
  { key: 'carga_no_coincide',     label: 'Carga no coincide',        icono: 'fa-scale-unbalanced',    color: '#e8590c' },
];

function renderResultados(data) {
  // El texto de arriba dice qué estás mirando: un equipo, una flota, o todo.
  const cliente = document.getElementById('filtroCliente').value;
  let filtroTexto = 'todas las flotas';
  if (equipoSeleccionado) {
    filtroTexto = `equipo ${equipoSeleccionado.nombre}`;
  } else if (cliente === '__sin_cliente__') {
    filtroTexto = 'equipos sin cliente asignado';
  } else if (cliente) {
    filtroTexto = `flota ${cliente}`;
  }
  document.getElementById('resumenResultados').textContent =
    `${data.total} anomalía(s) — ${filtroTexto}, últimos ${document.getElementById('filtroDias').value} día(s)`;

  renderPorFlota(data.anomalias, Boolean(cliente || equipoSeleccionado));

  // ---- Indicadores de aguja ----
  // Todos muestran una proporción (de 0 a 100%), así la aguja siempre
  // significa algo concreto:
  //   Total         -> cuánto de lo detectado ya revisaste (alto = bien)
  //   Por revisar   -> qué parte sigue pendiente (alto = mal)
  //   Mermas        -> qué parte de los litros ya está confirmada
  //   Vehículos     -> qué parte de la flota activa tuvo alguna anomalía
  //   Cada tipo     -> qué parte del total es de ese tipo
  const st = data.stats || {};
  const totalRevisables = (st.pendientes || 0) + (st.validadas || 0) + (st.descartadas || 0);
  const revisadas = (st.validadas || 0) + (st.descartadas || 0);
  const flota = st.flota_activa || 0;

  let html = '';
  html += gauge({ datos: { accion: 'todo' }, titulo: 'Total anomalías', icono: 'fa-triangle-exclamation', color: '#e03131',
    valor: numeroAR(data.total), sub: `${numeroAR(revisadas)} revisadas de ${numeroAR(totalRevisables)}`,
    pct: totalRevisables ? revisadas / totalRevisables : 0, invertido: true });
  html += gauge({ datos: { revision: 'pendiente' }, titulo: 'Por revisar', icono: 'fa-bell', color: '#f59f00',
    valor: numeroAR(st.pendientes), sub: 'esperando validar o descartar',
    pct: totalRevisables ? (st.pendientes || 0) / totalRevisables : 0 });
  html += gauge({ datos: { revision: 'validada' }, titulo: 'Mermas', icono: 'fa-gas-pump', color: '#1c7ed6',
    valor: `${numeroAR(st.litros_perdidos_totales, 1)} L`, sub: `${numeroAR(st.litros_validados, 1)} L confirmados`,
    pct: st.litros_perdidos_totales ? (st.litros_validados || 0) / st.litros_perdidos_totales : 0, invertido: true });
  html += gauge({ datos: { accion: 'porflota' }, titulo: 'Vehículos afectados', icono: 'fa-truck', color: '#7048e8',
    valor: numeroAR(st.equipos_afectados), sub: `de ${numeroAR(flota)} en la flota activa`,
    pct: flota ? (st.equipos_afectados || 0) / flota : 0 });

  // Los 9 tipos van en UNA tarjeta con una barra por tipo (antes eran 9
  // tarjetas y ocupaban media pantalla). La barra es la parte del total que
  // corresponde a ese tipo. Cada fila es clickeable: filtra ese tipo.
  const porTipo = {};
  data.anomalias.forEach(a => {
    if (!porTipo[a.tipo]) porTipo[a.tipo] = { n: 0, litros: 0 };
    porTipo[a.tipo].n++;
    porTipo[a.tipo].litros += Number(a.litros_perdidos) || 0;
  });
  const maxTipo = Math.max(1, ...TIPOS_FIJOS.map(t => (porTipo[t.key] || { n: 0 }).n));
  const filasTipo = TIPOS_FIJOS.map(t => {
    const info = porTipo[t.key] || { n: 0, litros: 0 };
    const ancho = (info.n / maxTipo * 100).toFixed(1);
    return `
      <div class="tipo-fila clickeable" data-tipo="${t.key}">
        <i class="fa-solid ${t.icono}"></i>
        <span class="tipo-nombre">${t.label}</span>
        <span class="tipo-barra"><span data-ancho="${ancho}" style="width:0"></span></span>
        <span class="tipo-num">${numeroAR(info.n)}</span>
        <span class="tipo-litros">${info.litros > 0 ? numeroAR(info.litros, 1) + ' L' : '—'}</span>
      </div>`;
  }).join('');
  html = `<div class="kpi-fila">${html}</div>
    <div class="card tipos-card">
      <div class="tipos-cab"><span>Anomalías por tipo</span><span class="tipos-ayuda">tocá un tipo para filtrarlo</span></div>
      ${filasTipo}
    </div>`;

  const $kpi = document.getElementById('kpiStats');
  $kpi.className = '';
  $kpi.removeAttribute('style');
  $kpi.innerHTML = html;
  animarAgujas($kpi);
  engancharClicksTarjetas($kpi);

  // ---- Tabla ----
  const $tabla = document.getElementById('tablaAnomalias');
  if (data.anomalias.length === 0) {
    $tabla.innerHTML = `<div class="empty-state">Sin anomalías con estos filtros. 👍</div>`;
    return;
  }

  const filas = data.anomalias.map(a => `
    <tr data-id="${a.id}">
      <td>${formatearFechaCorta(a.fecha)}</td>
      <td><a href="informe.html?equipo=${encodeURIComponent(a.equipo)}&tipo=semanal">${escapeHtml(a.equipo_nombre)}</a></td>
      <td><span class="tag ${escapeHtml(a.tipo)}">${escapeHtml(a.tipo.replace(/_/g, ' '))}</span></td>
      <td>${escapeHtml(a.detalle)}${a.comentario_revision ? `<div style="margin-top:4px;color:#777;font-size:12px;"><i class="fa-regular fa-comment"></i> ${escapeHtml(a.comentario_revision)}</div>` : ''}</td>
      <td>${Number(a.litros_perdidos).toFixed(1)} L</td>
      <td>${renderUbicacion(a.lat, a.lng)}</td>
      <td>${renderLinkGraficoTTM(a.equipo_nombre, a.fecha)}</td>
      <td>${renderRevision(a)}</td>
    </tr>
  `).join('');

  $tabla.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th><th>Gráfico</th><th>Revisión</th></tr>
      ${filas}
    </table>
  `;

  $tabla.querySelectorAll('.rev-btn').forEach(b => {
    b.addEventListener('click', () => {
      // Uso la misma lógica que la campanita (pide comentario al descartar,
      // guarda, y avisa para que todo se actualice).
      window.revisarAnomalia(Number(b.closest('tr').dataset.id), b.dataset.accion);
    });
  });
}

/**
 * Click en las tarjetas de arriba: te llevan a esas anomalías.
 *   - un tipo        -> filtra ese tipo y baja a la tabla (otro click lo saca)
 *   - Por revisar    -> solo las pendientes
 *   - Mermas         -> solo las validadas (las mermas confirmadas)
 *   - Total          -> saca el filtro de tipo y vuelve a las activas
 *   - Vehículos      -> baja al ranking por flota
 */
function engancharClicksTarjetas($contenedor) {
  const tipoActual = document.getElementById('filtroTipo').value;
  $contenedor.querySelectorAll('[data-tipo]').forEach(c => {
    if (c.dataset.tipo === tipoActual) c.classList.add('seleccionada');
  });
  $contenedor.querySelectorAll('.clickeable').forEach(c => {
    c.addEventListener('click', async () => {
      const d = c.dataset;
      if (d.tipo) {
        const $tipo = document.getElementById('filtroTipo');
        $tipo.value = $tipo.value === d.tipo ? '' : d.tipo;
      } else if (d.revision) {
        document.getElementById('filtroRevision').value = d.revision;
      } else if (d.accion === 'todo') {
        document.getElementById('filtroTipo').value = '';
        document.getElementById('filtroRevision').value = 'activas';
      } else if (d.accion === 'porflota') {
        document.getElementById('cardPorFlota').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
      await cargarAnomalias();
      document.getElementById('tablaAnomalias').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
}

/** Estado de revisión de una anomalía + los botones que correspondan. */
function renderRevision(a) {
  const estado = a.estado_revision || 'pendiente';
  const etiqueta = { pendiente: 'Pendiente', validada: 'Validada', descartada: 'Descartada' }[estado] || estado;
  const botones = estado === 'pendiente'
    ? `<button class="rev-btn validar" data-accion="validar" title="Pasó de verdad"><i class="fa-solid fa-check"></i></button>
       <button class="rev-btn descartar" data-accion="descartar" title="Falsa alarma"><i class="fa-solid fa-xmark"></i></button>`
    : `<button class="rev-btn reabrir" data-accion="reabrir" title="Volver a pendiente"><i class="fa-solid fa-rotate-left"></i></button>`;
  return `<span class="rev-estado ${estado}">${etiqueta}</span><div class="rev-botones">${botones}</div>`;
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

  // Nunca pedir una fecha en el futuro: si la anomalía es de hoy, "un día
  // después" cae mañana, y el reportes.php de TTM con una fecha futura no
  // muestra nada (así dejó de andar el link con las anomalías del día).
  // Tope: hoy a las 23:59, igual que el link de ejemplo de Emiliano.
  const finDeHoy = new Date();
  finDeHoy.setHours(23, 59, 0, 0);
  if (hasta > finDeHoy) {
    hasta.setTime(finDeHoy.getTime());
  }

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
      document.getElementById('filtroCliente').value = flota === 'Sin cliente asignado' ? '__sin_cliente__' : flota;
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