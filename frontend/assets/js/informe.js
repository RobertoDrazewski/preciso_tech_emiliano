const params = new URLSearchParams(window.location.search);
const equipoId = params.get('equipo');
const tipo = params.get('tipo') || 'diario';
const tipoLabel = { diario: 'Diario', semanal: 'Semanal', mensual: 'Mensual' }[tipo] || 'Diario';

let chartCombustibleInstance = null;
let chartVelocidadInstance = null;
let chartTanquesInstance = null;
let mapaInstance = null;
let ULTIMO_INFORME = null; // guardamos la última respuesta cargada, la usa el PDF

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

    ULTIMO_INFORME = data;

    document.getElementById('tituloPagina').textContent = `Preciso · Informe ${tipoLabel} · ${data.equipo.nombre}`;
    document.getElementById('titulo').textContent = `Informe ${tipoLabel} — ${data.equipo.nombre}`;
    document.getElementById('subtitulo').textContent =
      `${formatearFecha(data.desde)} — ${formatearFecha(data.hasta)}`;
    document.getElementById('bannerSimulado').classList.toggle('hidden', !data.simulado);

    renderStats(data.reporte);
    renderCharts(data.reporte);
    renderMapa(data.reporte);
    renderAnomalias(data.reporte);

    document.getElementById('btnDescargarPdf').classList.remove('hidden');

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
  // Esri World Imagery (satelital) + capas de referencia (calles y nombres
  // de lugares) encima, para vista híbrida. Gratis, sin API key nunca, y
  // con buen soporte de CORS — necesario para poder capturar el mapa como
  // imagen al exportar el PDF (con OSM directo, html2canvas suele dejar
  // el mapa en blanco por CORS; CARTO empezó a pedir API key en set/2026,
  // así que se dejó de usar).
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri',
    crossOrigin: true,
  }).addTo(mapaInstance);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {
    crossOrigin: true,
  }).addTo(mapaInstance);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
    crossOrigin: true,
  }).addTo(mapaInstance);

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

// ---------- Exportar a PDF ----------

document.getElementById('btnDescargarPdf').addEventListener('click', generarPDF);

async function generarPDF() {
  if (!ULTIMO_INFORME) return;

  const $btn = document.getElementById('btnDescargarPdf');
  const textoOriginal = $btn.textContent;
  $btn.disabled = true;
  $btn.textContent = '⏳ Generando PDF…';

  try {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const anchoPagina = doc.internal.pageSize.getWidth();
    const altoPagina = doc.internal.pageSize.getHeight();
    const margen = 14;
    const anchoUtil = anchoPagina - margen * 2;
    let y = margen;

    function saltoDePaginaSiHaceFalta(alturaNecesaria) {
      if (y + alturaNecesaria > altoPagina - margen) {
        doc.addPage();
        y = margen;
      }
    }

    // --- Encabezado ---
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.setTextColor(13, 27, 46);
    doc.text('Preciso · Informe de flota', margen, y);
    y += 8;

    doc.setFontSize(12);
    doc.text(`${tipoLabel} — ${ULTIMO_INFORME.equipo.nombre} (equipo ${ULTIMO_INFORME.equipo.id})`, margen, y);
    y += 6;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9.5);
    doc.setTextColor(107, 114, 128);
    doc.text(`Período: ${formatearFecha(ULTIMO_INFORME.desde)} — ${formatearFecha(ULTIMO_INFORME.hasta)}`, margen, y);
    y += 5;
    doc.text(`Generado: ${new Date().toLocaleString('es-AR')}`, margen, y);
    y += 5;
    if (ULTIMO_INFORME.simulado) {
      doc.setTextColor(180, 90, 0);
      doc.text('⚠ Datos SIMULADOS — la API real estaba fuera de servicio al momento de generar este informe.', margen, y);
      y += 5;
    }
    y += 3;

    // --- Resumen numérico ---
    const r = ULTIMO_INFORME.reporte;
    const resumen = [
      ['Km recorridos', r.km_recorridos !== null ? `${formatNum(r.km_recorridos)} km` : '—'],
      ['Combustible consumido', `${formatNum(r.litros_consumidos_total)} L`],
      ['Consumo promedio', r.consumo_l_100km !== null ? `${formatNum(r.consumo_l_100km)} L/100km` : '—'],
      ['Litros perdidos en anomalías', `${formatNum(r.litros_perdidos_anomalos)} L`],
      ['Anomalías detectadas', `${r.cantidad_anomalias}`],
      ['Lecturas recibidas', `${r.cantidad_lecturas}`],
    ];
    doc.autoTable({
      startY: y,
      margin: { left: margen, right: margen },
      body: resumen,
      theme: 'plain',
      styles: { fontSize: 9.5, cellPadding: 1.2 },
      columnStyles: { 0: { fontStyle: 'bold', textColor: [13, 27, 46] }, 1: { textColor: [30, 30, 30] } },
    });
    y = doc.lastAutoTable.finalY + 8;

    // --- Gráficos (imagen directa del canvas, calidad exacta) ---
    await agregarGrafico(doc, chartCombustibleInstance, 'Nivel de combustible (litros)', margen, anchoUtil, () => y, (v) => (y = v), saltoDePaginaSiHaceFalta);
    if (chartTanquesInstance) {
      await agregarGrafico(doc, chartTanquesInstance, 'Detalle por tanque', margen, anchoUtil, () => y, (v) => (y = v), saltoDePaginaSiHaceFalta);
    }
    await agregarGrafico(doc, chartVelocidadInstance, 'Velocidad (km/h)', margen, anchoUtil, () => y, (v) => (y = v), saltoDePaginaSiHaceFalta);

    // --- Mapa (screenshot real vía html2canvas, tiles de Esri por el tema de CORS) ---
    const $cardMapa = document.getElementById('cardMapa');
    if (mapaInstance && !$cardMapa.classList.contains('hidden')) {
      saltoDePaginaSiHaceFalta(14);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.setTextColor(13, 27, 46);
      doc.text('Recorrido', margen, y);
      y += 5;

      try {
        const mapaCanvas = await html2canvas(document.getElementById('mapa'), { useCORS: true, logging: false });
        const alturaImg = (mapaCanvas.height * anchoUtil) / mapaCanvas.width;
        saltoDePaginaSiHaceFalta(alturaImg);
        doc.addImage(mapaCanvas.toDataURL('image/png'), 'PNG', margen, y, anchoUtil, alturaImg);
        y += alturaImg + 8;
      } catch (e) {
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);
        doc.setTextColor(180, 40, 40);
        doc.text('No se pudo capturar el mapa para este PDF.', margen, y);
        y += 8;
      }
    }

    // --- Tabla de anomalías ---
    saltoDePaginaSiHaceFalta(14);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(13, 27, 46);
    doc.text('Anomalías del período', margen, y);
    y += 4;

    if (r.anomalias.length === 0) {
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9.5);
      doc.setTextColor(60, 130, 80);
      doc.text('Sin anomalías detectadas en este período.', margen, y + 4);
      y += 10;
    } else {
      doc.autoTable({
        startY: y,
        margin: { left: margen, right: margen },
        head: [['Fecha', 'Tipo', 'Detalle', 'Litros', 'Ubicación']],
        body: r.anomalias.map(a => [
          formatearFechaCorta(a.fecha),
          a.tipo.replace(/_/g, ' '),
          a.detalle,
          `${formatNum(a.litros_perdidos)} L`,
          (a.lat !== null && a.lat !== undefined && a.lng !== null && a.lng !== undefined) 
            ? `${Number(a.lat).toFixed(5)}, ${Number(a.lng).toFixed(5)}` 
            : '—',
        ]),
        styles: { fontSize: 8, cellPadding: 2 },
        headStyles: { fillColor: [13, 27, 46], textColor: [255, 255, 255] },
        columnStyles: { 2: { cellWidth: 60 } },
      });
      y = doc.lastAutoTable.finalY + 6;
    }

    doc.setFont('helvetica', 'italic');
    doc.setFontSize(8);
    doc.setTextColor(150, 150, 150);
    doc.text('Preciso · Módulo de informes y detección de anomalías — desarrollado por Puma Code', margen, altoPagina - 8);

    const nombreArchivo = `preciso_informe_${equipoId}_${tipo}_${new Date().toISOString().slice(0, 10)}.pdf`;
    doc.save(nombreArchivo);

    // Se generó y descargó el PDF -> las anomalías de este período quedan
    // "atendidas". Esto es lo que hace bajar la alarma/badge del panel.
    await marcarResueltasYRefrescarBadge();
  } catch (e) {
    alert('No se pudo generar el PDF: ' + e.message);
  } finally {
    $btn.disabled = false;
    $btn.textContent = textoOriginal;
  }
}

async function marcarResueltasYRefrescarBadge() {
  if (!ULTIMO_INFORME) return;
  try {
    await fetch(`${window.PRECISO_CONFIG.API_BASE}/marcar_resueltas.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        equipo: ULTIMO_INFORME.equipo.id,
        desde: ULTIMO_INFORME.desde,
        hasta: ULTIMO_INFORME.hasta,
      }),
    });
    if (typeof refrescarBadgePendientes === 'function') {
      refrescarBadgePendientes(); // definido en badge.js
    }
  } catch (e) {
    // Si esto falla, no rompe la descarga del PDF (que ya se hizo) — la
    // próxima vez que se genere un informe se vuelve a intentar.
  }
}

async function agregarGrafico(doc, chartInstance, titulo, margen, anchoUtil, getY, setY, saltoDePaginaSiHaceFalta) {
  if (!chartInstance) return;
  let y = getY();

  saltoDePaginaSiHaceFalta(14);
  y = getY();
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.setTextColor(13, 27, 46);
  doc.text(titulo, margen, y);
  y += 4;

  const canvas = chartInstance.canvas;
  const alturaImg = (canvas.height * anchoUtil) / canvas.width;
  saltoDePaginaSiHaceFalta(alturaImg);
  y = getY();
  doc.addImage(canvas.toDataURL('image/png', 1.0), 'PNG', margen, y, anchoUtil, alturaImg);
  y += alturaImg + 8;
  setY(y);
}

cargarInforme();