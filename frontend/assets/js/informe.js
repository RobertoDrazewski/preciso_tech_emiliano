const params = new URLSearchParams(window.location.search);
const equipoId = params.get('equipo');
const tipo = params.get('tipo') || 'diario';
const tipoLabel = { diario: 'Diario', semanal: 'Semanal', mensual: 'Mensual' }[tipo] || 'Diario';

let chartCombustibleInstance = null;
let chartVelocidadInstance = null;
let chartTanquesInstance = null;
let mapaInstance = null;

function renderTabs() {
  const tabs = [
    ['diario', 'Diario'],
    ['semanal', 'Semanal'],
    ['mensual', 'Mensual'],
  ];
  document.getElementById('tabs').innerHTML = tabs.map(([t, label]) => `
    <a href="informe.html?equipo=${encodeURIComponent(equipoId)}&tipo=${t}" class="${t === tipo ? 'active' : ''}">${label}</a>
  `).join('');
}

async function cargarInforme() {
  const $loading = document.getElementById('loading');
  const $error = document.getElementById('error');
  const $contenido = document.getElementById('contenido');

  if (!equipoId) {
    $loading.classList.add('hidden');
    $error.classList.remove('hidden');
    $error.innerHTML = '<strong>Falta el equipo.</strong> Volvé al <a href="index.html">panel</a> y elegí uno.';
    return;
  }

  renderTabs();

  try {
    const url = `${window.PRECISO_CONFIG.API_BASE}/informe.php?equipo=${encodeURIComponent(equipoId)}&tipo=${encodeURIComponent(tipo)}`;
    const resp = await fetch(url);
    const data = await resp.json();

    if (!resp.ok) {
      throw new Error(data.error || `Error HTTP ${resp.status}`);
    }

    document.getElementById('tituloPagina').textContent = `Preciso · Informe ${tipoLabel} · ${data.equipo.nombre}`;
    document.getElementById('titulo').textContent = `Informe ${tipoLabel} — ${data.equipo.nombre}`;
    document.getElementById('subtitulo').textContent =
      `${formatearFecha(data.desde)} — ${formatearFecha(data.hasta)}`;
    document.getElementById('bannerSimulado').classList.toggle('hidden', !data.simulado);

    renderStats(data.reporte);
    renderCharts(data.reporte);
    renderMapa(data.reporte);
    renderAnomalias(data.reporte);

    $loading.classList.add('hidden');
    $contenido.classList.remove('hidden');
  } catch (e) {
    $loading.classList.add('hidden');
    $error.classList.remove('hidden');
    $error.innerHTML = `<strong>No se pudo generar el informe:</strong> ${escapeHtml(e.message)}
      <p style="margin-top:8px; color:var(--color-text-muted); font-size:12.5px;">
        Si es la primera vez que corrés esto, revisá el README del backend → ajustar
        <code>config/field_map.php</code> con la respuesta real de la API.
      </p>`;
  }
}

function renderStats(r) {
  document.getElementById('stats').innerHTML = `
    <div class="stat">
      <div class="label">Km recorridos</div>
      <div class="value">${r.km_recorridos !== null ? formatNum(r.km_recorridos) + ' km' : '—'}</div>
    </div>
    <div class="stat">
      <div class="label">Combustible consumido</div>
      <div class="value">${formatNum(r.litros_consumidos_total)} L</div>
    </div>
    <div class="stat">
      <div class="label">Consumo promedio</div>
      <div class="value">${r.consumo_l_100km !== null ? formatNum(r.consumo_l_100km) + ' L/100km' : '—'}</div>
    </div>
    <div class="stat">
      <div class="label">Litros perdidos en anomalías</div>
      <div class="value ${r.litros_perdidos_anomalos > 0 ? 'danger' : 'success'}">${formatNum(r.litros_perdidos_anomalos)} L</div>
    </div>
    <div class="stat">
      <div class="label">Anomalías detectadas</div>
      <div class="value ${r.cantidad_anomalias > 0 ? 'danger' : 'success'}">${r.cantidad_anomalias}</div>
    </div>
    <div class="stat">
      <div class="label">Lecturas recibidas</div>
      <div class="value">${r.cantidad_lecturas}</div>
    </div>
  `;
}

function renderCharts(r) {
  if (chartCombustibleInstance) chartCombustibleInstance.destroy();
  if (chartVelocidadInstance) chartVelocidadInstance.destroy();
  if (chartTanquesInstance) { chartTanquesInstance.destroy(); chartTanquesInstance = null; }

  chartCombustibleInstance = new Chart(document.getElementById('chartCombustible'), {
    type: 'line',
    data: { datasets: [{ label: 'Combustible (L)', data: r.serie_combustible, borderColor: '#f2a71b', backgroundColor: 'rgba(242,167,27,0.15)', fill: true, tension: 0.25, pointRadius: 0 }] },
    options: { scales: { x: { type: 'time' }, y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
  });

  chartVelocidadInstance = new Chart(document.getElementById('chartVelocidad'), {
    type: 'line',
    data: { datasets: [{ label: 'Velocidad km/h', data: r.serie_velocidad, borderColor: '#0d1b2e', backgroundColor: 'rgba(13,27,46,0.08)', fill: true, tension: 0.25, pointRadius: 0 }] },
    options: { scales: { x: { type: 'time' } }, plugins: { legend: { display: false } } }
  });

  const coloresTanque = { 1: '#f2a71b', 2: '#0d1b2e', 3: '#1f9d55', 4: '#c0392b' };
  const tanques = r.series_tanques || {};
  const numerosConDatos = Object.keys(tanques).filter(n => tanques[n] && tanques[n].length > 0);

  const $cardTanques = document.getElementById('cardTanques');
  if (numerosConDatos.length === 0) {
    $cardTanques.classList.add('hidden');
    return;
  }
  $cardTanques.classList.remove('hidden');

  chartTanquesInstance = new Chart(document.getElementById('chartTanques'), {
    type: 'line',
    data: {
      datasets: numerosConDatos.map(n => ({
        label: `Tanque ${n}`,
        data: tanques[n],
        borderColor: coloresTanque[n] || '#888',
        backgroundColor: 'transparent',
        tension: 0.25,
        pointRadius: 0,
      })),
    },
    options: { scales: { x: { type: 'time' }, y: { beginAtZero: true } }, plugins: { legend: { display: true } } }
  });
}

function renderMapa(r) {
  const puntos = r.puntos_mapa || [];
  if (puntos.length === 0) {
    return;
  }
  document.getElementById('cardMapa').classList.remove('hidden');

  if (mapaInstance) {
    mapaInstance.remove();
  }
  mapaInstance = L.map('mapa');
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(mapaInstance);

  const latlngs = puntos.map(p => [p.lat, p.lng]);
  L.polyline(latlngs, { color: '#f2a71b', weight: 3 }).addTo(mapaInstance);
  L.marker(latlngs[0]).addTo(mapaInstance).bindPopup('Inicio');
  L.marker(latlngs[latlngs.length - 1]).addTo(mapaInstance).bindPopup('Última posición');
  // maxZoom acá es clave: si todos los puntos están muy cerca entre sí (o
  // son el mismo, como pasa con datos de prueba fijos), fitBounds sin tope
  // hace un zoom altísimo donde el tile server de OpenStreetMap no tiene
  // tiles cacheados, y el mapa queda gris/vacío sin ningún error visible.
  mapaInstance.fitBounds(latlngs, { maxZoom: 15 });

  // El mapa se inicializa mientras #contenido todavía está oculto
  // (display:none), así que Leaflet calcula mal el tamaño del contenedor
  // y los tiles quedan a medio cargar. Forzamos un recálculo apenas el
  // navegador termina de mostrar el contenedor (siguiente tick).
  setTimeout(() => {
    if (mapaInstance) {
      mapaInstance.invalidateSize();
      mapaInstance.fitBounds(latlngs, { maxZoom: 15 });
    }
  }, 150);
}

function renderAnomalias(r) {
  const $div = document.getElementById('anomalias');
  if (!r.anomalias || r.anomalias.length === 0) {
    $div.innerHTML = '<div class="empty-state">Sin anomalías detectadas en este período. 👍</div>';
    return;
  }

  const filas = r.anomalias.map(a => `
    <tr>
      <td>${formatearFechaCorta(a.fecha)}</td>
      <td><span class="tag ${escapeHtml(a.tipo)}">${escapeHtml(a.tipo.replace(/_/g, ' '))}</span></td>
      <td>${escapeHtml(a.detalle)}</td>
      <td>${formatNum(a.litros_perdidos)} L</td>
      <td>${renderUbicacion(a.lat, a.lng)}</td>
    </tr>
  `).join('');

  $div.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th></tr>
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

function formatNum(n) {
  return Number(n).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
function formatearFecha(iso) {
  const d = new Date(iso);
  return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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

cargarInforme();
