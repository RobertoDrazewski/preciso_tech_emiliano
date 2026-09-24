let TODOS_LOS_EQUIPOS = [];
const LIMITE_RENDER = 100; // con 1000+ equipos, no tiene sentido pintar todos de una

async function cargarDashboard() {
  const $loading = document.getElementById('loading');
  const $error = document.getElementById('error');
  const $contenido = document.getElementById('contenido');

  try {
    const resp = await fetch(`${window.PRECISO_CONFIG.API_BASE}/equipos.php`);
    const data = await resp.json();

    if (!resp.ok) {
      throw new Error(data.error || `Error HTTP ${resp.status}`);
    }

    TODOS_LOS_EQUIPOS = data.equipos;
    renderStats(TODOS_LOS_EQUIPOS);
    renderLista(TODOS_LOS_EQUIPOS);

    document.getElementById('bannerSimulado').classList.toggle('hidden', !data.simulado);
    document.getElementById('buscarEquipo').addEventListener('input', aplicarFiltros);
    document.getElementById('filtroSoloAnomalias').addEventListener('change', aplicarFiltros);
    document.getElementById('filtroServicio').addEventListener('change', aplicarFiltros);

    $loading.classList.add('hidden');
    $contenido.classList.remove('hidden');
  } catch (e) {
    $loading.classList.add('hidden');
    $error.classList.remove('hidden');
    $error.innerHTML = `<strong>No se pudo cargar la flota:</strong> ${escapeHtml(e.message)}
      <p style="margin-top:8px; color:var(--color-text-muted); font-size:12.5px;">
        ¿Está corriendo el backend? Revisá que <code>API_BASE</code> en
        <code>assets/js/config.php</code> apunte a donde tenés
        <code>php -S localhost:8000 -t public</code> corriendo desde la carpeta backend/.
      </p>`;
  }
}

function aplicarFiltros() {
  const q = document.getElementById('buscarEquipo').value.trim().toLowerCase();
  const soloConAnomalias = document.getElementById('filtroSoloAnomalias').checked;
  const servicio = document.getElementById('filtroServicio').value;

  let filtrados = TODOS_LOS_EQUIPOS;

  if (q !== '') {
    filtrados = filtrados.filter(eq =>
      eq.nombre.toLowerCase().includes(q) ||
      eq.id.toLowerCase().includes(q) ||
      (eq.cliente && eq.cliente.toLowerCase().includes(q))
    );
  }
  if (soloConAnomalias) {
    filtrados = filtrados.filter(eq => eq.anomalias_30d > 0);
  }
  if (servicio !== '') {
    filtrados = filtrados.filter(eq => eq.servicio === servicio);
  }

  renderLista(filtrados, q);
}

/**
 * Indicadores de aguja del panel. Cada aguja muestra una proporción:
 *   Flota monitoreada  -> equipos activos que el sistema está siguiendo
 *   Con anomalías      -> qué parte de la flota tuvo alguna en 30 días
 *   Anomalías 30 días  -> cuántas son de los equipos con servicio FULL
 *   Por revisar        -> qué parte de lo detectado espera validación
 */
async function renderStats(equipos) {
  const total = equipos.length;
  const conAnomalias = equipos.filter(e => e.anomalias_30d > 0).length;
  const anomalias30 = equipos.reduce((acc, e) => acc + e.anomalias_30d, 0);
  const anomaliasFull = equipos.filter(e => e.servicio === 'FULL').reduce((acc, e) => acc + e.anomalias_30d, 0);

  // El número de "por revisar" sale del mismo endpoint que la campanita.
  let pendientes = 0;
  try {
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/revision.php?limite=1`);
    if (r.ok) pendientes = (await r.json()).pendientes || 0;
  } catch (e) { /* si falla, queda en 0 */ }

  const $stats = document.getElementById('stats');
  $stats.className = 'gauges';
  $stats.innerHTML =
    gauge({ titulo: 'Flota monitoreada', icono: 'fa-truck-fast', color: '#2f9e44',
      valor: numeroAR(total), sub: 'equipos activos', pct: total ? 1 : 0, invertido: true }) +
    gauge({ titulo: 'Con anomalías', icono: 'fa-truck-medical', color: '#e03131',
      valor: numeroAR(conAnomalias), sub: `${total ? Math.round(conAnomalias / total * 100) : 0}% de la flota (30 días)`,
      pct: total ? conAnomalias / total : 0 }) +
    gauge({ titulo: 'Anomalías 30 días', icono: 'fa-triangle-exclamation', color: '#f59f00',
      valor: numeroAR(anomalias30), sub: `${numeroAR(anomaliasFull)} de clientes FULL`,
      pct: anomalias30 ? anomaliasFull / anomalias30 : 0 }) +
    gauge({ titulo: 'Por revisar', icono: 'fa-bell', color: '#1c7ed6',
      valor: numeroAR(pendientes), sub: 'esperando validar o descartar',
      pct: anomalias30 ? Math.min(1, pendientes / anomalias30) : 0 });
  animarAgujas($stats);
}

// Si validan o descartan desde la campanita, actualizo los indicadores.
window.addEventListener('preciso:revision', () => renderStats(TODOS_LOS_EQUIPOS));

function renderLista(equipos, busqueda) {
  const $lista = document.getElementById('listaEquipos');
  const $contador = document.getElementById('contadorEquipos');

  if (equipos.length === 0) {
    $contador.textContent = '';
    $lista.innerHTML = `<li class="empty-state">No hay equipos${busqueda ? ` que coincidan con "${escapeHtml(busqueda)}"` : ' cargados todavía'}.</li>`;
    return;
  }

  const aMostrar = equipos.slice(0, LIMITE_RENDER);
  $contador.textContent = equipos.length > LIMITE_RENDER
    ? `Mostrando ${LIMITE_RENDER} de ${equipos.length} — refiná la búsqueda para ver otros`
    : `${equipos.length} equipo(s)`;

  $lista.innerHTML = aMostrar.map(eq => `
    <li>
      <div>
        <div class="equipo-nombre">${escapeHtml(eq.nombre)}</div>
        <div class="equipo-id">
          Equipo ${escapeHtml(eq.id)}
          ${eq.cliente ? `· ${escapeHtml(eq.cliente)}` : ''}
          ${eq.servicio ? `· <span style="text-transform:capitalize;">${escapeHtml(eq.servicio.toLowerCase())}</span>` : ''}
          ${eq.anomalias_30d > 0 ? `· <span style="color:var(--color-danger); font-weight:700;">${eq.anomalias_30d} anomalía(s)</span>` : ''}
        </div>
      </div>
      <div>
        <a class="btn secondary" href="anomalias.html?equipo=${encodeURIComponent(eq.id)}">Anomalías</a>
        <a class="btn secondary" href="informe.html?equipo=${encodeURIComponent(eq.id)}&tipo=diario">Diario</a>
        <a class="btn secondary" href="informe.html?equipo=${encodeURIComponent(eq.id)}&tipo=semanal">Semanal</a>
        <a class="btn" href="informe.html?equipo=${encodeURIComponent(eq.id)}&tipo=mensual">Mensual</a>
      </div>
    </li>
  `).join('');
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

cargarDashboard();
