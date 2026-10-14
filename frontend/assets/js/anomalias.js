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
  // Al validar/rechazar/etc. actualizo solo esa fila (sin recargar todo).
  window.addEventListener('preciso:recalculo-terminado', cargarAnomalias);
  window.addEventListener('preciso:revision-error', cargarAnomalias);
  window.addEventListener('preciso:revision', (ev) => {
    const a = aplicarAccionLocal(ev.detail || {});
    const $fila = a && document.querySelector(`#tablaAnomalias tr[data-id="${a.id}"]`);
    if (!$fila) { cargarAnomalias(); return; }
    const $celdas = $fila.querySelectorAll('td');
    const $ticket = $celdas[$celdas.length - 1];
    $ticket.innerHTML = `${renderEstadoTicket(a)}<div class="rev-botones">${renderAccionesTicket(a)}</div>`;
    engancharTickets($ticket);
  });
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
  registrarAnomalias(data.anomalias);

  // ---- Indicadores de aguja ----
  // Todos muestran una proporción (de 0 a 100%), así la aguja siempre
  // significa algo concreto:
  //   Total         -> cuánto de lo detectado ya revisaste (alto = bien)
  //   Por revisar   -> qué parte sigue pendiente (alto = mal)
  //   Mermas        -> qué parte de los litros ya está confirmada
  //   Vehículos     -> qué parte de la flota activa tuvo alguna anomalía
  //   Cada tipo     -> qué parte del total es de ese tipo
  const st = data.stats || {};
  const totalRevisables = (st.pendientes || 0) + (st.notificadas || 0) + (st.informadas || 0) + (st.rechazadas || 0);
  const revisadas = totalRevisables - (st.pendientes || 0);
  const flota = st.flota_activa || 0;

  let html = '';
  html += gauge({ datos: { accion: 'todo' }, titulo: 'Total anomalías', icono: 'fa-triangle-exclamation', color: '#e03131',
    valor: numeroAR(data.total), sub: `${numeroAR(revisadas)} revisadas de ${numeroAR(totalRevisables)}`,
    pct: totalRevisables ? revisadas / totalRevisables : 0, invertido: true });
  html += gauge({ datos: { revision: 'pendiente' }, titulo: 'Por notificar', icono: 'fa-inbox', color: '#f59f00',
    valor: numeroAR(st.pendientes), sub: 'tickets que nadie vio todavía',
    pct: totalRevisables ? (st.pendientes || 0) / totalRevisables : 0 });
  html += gauge({ datos: { revision: 'notificada' }, titulo: 'Mermas', icono: 'fa-gas-pump', color: '#1c7ed6',
    valor: `${numeroAR(st.litros_perdidos_totales, 1)} L`, sub: `${numeroAR(st.litros_confirmados, 1)} L en tickets notificados o informados`,
    pct: st.litros_perdidos_totales ? (st.litros_confirmados || 0) / st.litros_perdidos_totales : 0, invertido: true });
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
        <button class="tipo-ajustes" data-ajustes="${t.key}" title="Ajustar los umbrales de ${t.label}"><i class="fa-solid fa-sliders"></i></button>
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
      <td>${renderObservacion(a)}</td>
      <td>${renderEstadoTicket(a)}<div class="rev-botones">${renderAccionesTicket(a)}</div></td>
    </tr>
  `).join('');

  $tabla.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th><th>Gráfico</th><th style="min-width:200px">Observación</th><th>Ticket</th></tr>
      ${filas}
    </table>
  `;

  engancharTickets($tabla);
}

/**
 * Click en las tarjetas de arriba: te llevan a esas anomalías.
 *   - un tipo        -> filtra ese tipo y baja a la tabla (otro click lo saca)
 *   - Por revisar    -> solo las pendientes
 *   - Mermas         -> los tickets notificados (mermas corroboradas)
 *   - Total          -> saca el filtro de tipo y vuelve a las activas
 *   - Vehículos      -> baja al ranking por flota
 */
function engancharClicksTarjetas($contenedor) {
  const tipoActual = document.getElementById('filtroTipo').value;
  $contenedor.querySelectorAll('[data-tipo]').forEach(c => {
    if (c.dataset.tipo === tipoActual) c.classList.add('seleccionada');
  });
  // Botón de ajustes de cada tipo: abre sus umbrales (no filtra).
  $contenedor.querySelectorAll('.tipo-ajustes').forEach(b => {
    b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const t = TIPOS_FIJOS.find(x => x.key === b.dataset.ajustes);
      abrirParametros(b.dataset.ajustes, t ? t.label : b.dataset.ajustes);
    });
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

  // Todo en hora argentina (UTC-3), la de la plataforma de TTM, sin importar
  // la zona horaria de la computadora. Ventana: desde el día anterior a las
  // 0:00 hasta el día siguiente a las 23:59, pero nunca en el futuro (si la
  // anomalía es de hoy, hasta hoy a las 23:59: con fechas futuras TTM no
  // muestra nada).
  const p = partesAR(fechaISO);
  const hoy = partesAR(new Date());
  const desde = partesAR(fechaAR(p.y, p.m, p.d - 1));
  let hasta = partesAR(fechaAR(p.y, p.m, p.d + 1));
  if (fechaAR(hasta.y, hasta.m, hasta.d) > fechaAR(hoy.y, hoy.m, hoy.d)) hasta = hoy;

  const pad = (n) => String(n).padStart(2, '0');
  const dia = (x) => `${x.y}-${pad(x.m)}-${pad(x.d)}`;
  const params = new URLSearchParams({
    date_range: `${dia(desde)} 0:00 / ${dia(hasta)} 23:59`,
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
  return d.toLocaleString('es-AR', { timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

init();

/**
 * Ventana de umbrales de un tipo de anomalía (pedido de Emiliano: que los
 * umbrales se puedan ajustar sin tocar código). Cada campo se guarda con su
 * botón; "Volver al original" borra el ajuste y vuelve al de config.php.
 * El servidor valida el rango, así que acá solo muestro lo que responde.
 */
async function abrirParametros(tipo, nombre) {
  const $modal = document.getElementById('modalParametros');
  const $cuerpo = document.getElementById('modalCuerpo');
  document.getElementById('modalTitulo').textContent = `Umbrales · ${nombre}`;
  $cuerpo.innerHTML = '<p>Cargando…</p>';
  $modal.hidden = false;

  let params = [];
  try {
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/parametros.php`);
    params = (await r.json()).tipos[tipo] || [];
  } catch (e) {
    $cuerpo.innerHTML = '<p>No se pudieron cargar los umbrales.</p>';
    return;
  }
  if (params.length === 0) {
    $cuerpo.innerHTML = '<p>Este tipo no tiene umbrales para ajustar: lo marca directamente el sensor / el diagnóstico de TTM.</p>';
    return;
  }

  $cuerpo.innerHTML = params.map(p => `
    <div class="param" data-clave="${p.clave}">
      <label>${escapeHtml(p.nombre)} <span class="param-unidad">(${escapeHtml(p.unidad)})</span>
        ${p.modificado ? '<span class="param-mod">ajustado</span>' : ''}</label>
      <div class="param-fila">
        <input type="number" step="any" min="${p.min}" max="${p.max}" value="${p.valor}">
        <button type="button" class="btn param-guardar">Guardar</button>
        ${p.modificado ? `<button type="button" class="btn secondary param-restaurar" title="Original: ${p.por_defecto}">Volver al original (${p.por_defecto})</button>` : ''}
      </div>
      <div class="param-ayuda">${escapeHtml(p.ayuda)} Rango permitido: ${p.min} a ${p.max}.</div>
      <div class="param-estado"></div>
    </div>`).join('') + `
    <div class="recalc-caja">
      <div class="recalc-titulo"><i class="fa-solid fa-rotate"></i> Aplicar los umbrales a lo que ya pasó</div>
      <p>Los cambios valen solo para lo que se detecte de ahora en adelante. Para aplicarlos también a las anomalías
        de este tipo de los últimos 30 días, recalculá: se quitan las que ya no cumplen y se agregan las nuevas.
        Los tickets ya notificados, informados o rechazados <b>no se tocan</b>.</p>
      <button type="button" class="btn" id="btnRecalcular"><i class="fa-solid fa-rotate"></i> Recalcular ${escapeHtml(nombre)}</button>
      <div class="recalc-estado" id="recalcEstado"></div>
    </div>`;

  document.getElementById('btnRecalcular').addEventListener('click', async () => {
    if (!confirm(`¿Recalcular las anomalías de "${nombre}" de los últimos 30 días con los umbrales actuales?\n\nPuede tardar unos minutos. Podés cerrar esta ventana y seguir trabajando: te aviso cuando termine.`)) return;
    try {
      await window.iniciarRecalculo(tipo, 30);
      document.getElementById('recalcEstado').textContent = 'Recalculando… podés cerrar esta ventana, el avance se ve abajo a la derecha.';
    } catch (e) {
      document.getElementById('recalcEstado').textContent = e.message;
    }
  });

  const enviar = async (clave, cuerpo, $estado) => {
    try {
      const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/parametros.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ clave, ...cuerpo }),
      });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Error');
      abrirParametros(tipo, nombre); // recargo para mostrar "ajustado" / original
    } catch (e) {
      $estado.textContent = e.message;
    }
  };
  $cuerpo.querySelectorAll('.param').forEach($p => {
    const $estado = $p.querySelector('.param-estado');
    $p.querySelector('.param-guardar').addEventListener('click', () =>
      enviar($p.dataset.clave, { valor: $p.querySelector('input').value }, $estado));
    const $rest = $p.querySelector('.param-restaurar');
    if ($rest) $rest.addEventListener('click', () => enviar($p.dataset.clave, { restaurar: true }, $estado));
  });
}

document.getElementById('modalCerrar').addEventListener('click', () => { document.getElementById('modalParametros').hidden = true; });
document.getElementById('modalParametros').addEventListener('click', (ev) => {
  if (ev.target.id === 'modalParametros') ev.currentTarget.hidden = true;
});
