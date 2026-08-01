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

    renderDashboard(data.equipos);

    document.getElementById('bannerSimulado').classList.toggle('hidden', !data.simulado);

    $loading.classList.add('hidden');
    $contenido.classList.remove('hidden');
  } catch (e) {
    $loading.classList.add('hidden');
    $error.classList.remove('hidden');
    $error.innerHTML = `<strong>No se pudo cargar la flota:</strong> ${escapeHtml(e.message)}
      <p style="margin-top:8px; color:var(--color-text-muted); font-size:12.5px;">
        ¿Está corriendo el backend? Revisá que <code>API_BASE</code> en
        <code>assets/js/config.js</code> apunte a donde tenés
        <code>php -S localhost:8000 -t public</code> corriendo desde la carpeta backend/.
      </p>`;
  }
}

function renderDashboard(equipos) {
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

  const $lista = document.getElementById('listaEquipos');
  if (equipos.length === 0) {
    $lista.innerHTML = `<li class="empty-state">Todavía no cargaste ningún equipo en config/equipos.php</li>`;
    return;
  }

  $lista.innerHTML = equipos.map(eq => `
    <li>
      <div>
        <div class="equipo-nombre">${escapeHtml(eq.nombre)}</div>
        <div class="equipo-id">
          Equipo ${escapeHtml(eq.id)}
          ${eq.anomalias_30d > 0 ? `· <span style="color:var(--color-danger); font-weight:700;">${eq.anomalias_30d} anomalía(s)</span>` : ''}
        </div>
      </div>
      <div>
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
