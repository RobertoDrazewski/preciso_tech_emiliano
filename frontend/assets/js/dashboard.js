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
    document.getElementById('buscarEquipo').addEventListener('input', onBuscar);

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

function onBuscar(e) {
  const q = e.target.value.trim().toLowerCase();
  if (q === '') {
    renderLista(TODOS_LOS_EQUIPOS);
    return;
  }
  const filtrados = TODOS_LOS_EQUIPOS.filter(eq =>
    eq.nombre.toLowerCase().includes(q) ||
    eq.id.toLowerCase().includes(q) ||
    (eq.cliente && eq.cliente.toLowerCase().includes(q))
  );
  renderLista(filtrados, q);
}

function renderStats(equipos) {
  const totalAnomalias = equipos.reduce((acc, e) => acc + e.anomalias_30d, 0);

  document.getElementById('stats').innerHTML = `
    <div class="stat">
      <div class="label">Equipos monitoreados</div>
      <div class="value">${equipos.length}</div>
    </div>
    <div class="stat">
      <div class="label">Anomalías últimos 30 días</div>
      <div class="value ${totalAnomalias > 0 ? 'danger' : 'success'}">${totalAnomalias}</div>
    </div>
    <div class="stat">
      <div class="label">Estado del sistema</div>
      <div class="value success">Activo</div>
    </div>
  `;
}

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
