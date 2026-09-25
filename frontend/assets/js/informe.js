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

/**
 * Indicadores de aguja del informe. Las escalas dependen del período
 * (no es lo mismo un día que un mes):
 *   Km recorridos    -> contra un tope razonable del período
 *   Consumo promedio -> L/100km en escala de 0 a 80 (verde hasta ~32,
 *                       amarillo hasta ~56, rojo arriba: camión pesado)
 *   Combustible      -> contra lo que consumiría ese recorrido a 60 L/100km
 *   Litros perdidos  -> qué parte de todo lo que se fue del tanque fue anomalía
 *   Anomalías        -> contra 10 en el período (10 o más: aguja al máximo)
 *   Cobertura        -> lecturas recibidas contra las esperadas (una cada
 *                       5 minutos): muestra si el equipo reportó bien
 */
function renderStats(r) {
  const dias = { diario: 1, semanal: 7, mensual: 30 }[tipo] || 1;
  const km = r.km_recorridos || 0;
  const consumido = r.litros_consumidos_total || 0;
  const perdidos = r.litros_perdidos_anomalos || 0;
  const esperadas = dias * 24 * 12;
  // Anomalías y litros perdidos llevan a la pantalla de Anomalías de este equipo.
  const verAnomalias = `anomalias.html?equipo=${encodeURIComponent(equipoId)}&dias=${dias}`;

  const $stats = document.getElementById('stats');
  $stats.className = 'gauges';
  $stats.innerHTML =
    gauge({ titulo: 'Km recorridos', icono: 'fa-road', color: '#e03131',
      valor: r.km_recorridos !== null ? `${formatNum(km)} km` : '—', sub: `período ${tipoLabel.toLowerCase()}`,
      pct: km / (dias * 800), invertido: true }) +
    gauge({ titulo: 'Consumo promedio', icono: 'fa-gauge-high', color: '#1098ad',
      valor: r.consumo_l_100km !== null ? `${formatNum(r.consumo_l_100km)} L/100` : '—', sub: 'litros cada 100 km',
      pct: (r.consumo_l_100km || 0) / 80 }) +
    gauge({ titulo: 'Combustible consumido', icono: 'fa-droplet', color: '#f59f00',
      valor: `${formatNum(consumido)} L`, sub: km > 0 ? `a 60 L/100 serían ${formatNum(km * 0.6)} L` : '',
      pct: km > 0 ? consumido / (km * 0.6) : 0 }) +
    gauge({ enlace: verAnomalias, titulo: 'Litros perdidos', icono: 'fa-gas-pump', color: '#c92a2a',
      valor: `${formatNum(perdidos)} L`, sub: 'en anomalías del período',
      pct: (consumido + perdidos) > 0 ? perdidos / (consumido + perdidos) : 0 }) +
    gauge({ enlace: verAnomalias, titulo: 'Anomalías', icono: 'fa-triangle-exclamation', color: '#f76707',
      valor: String(r.cantidad_anomalias), sub: 'detectadas en el período',
      pct: r.cantidad_anomalias / 10 }) +
    gauge({ titulo: 'Cobertura de datos', icono: 'fa-satellite-dish', color: '#2f9e44',
      valor: `${Math.min(100, Math.round(r.cantidad_lecturas / esperadas * 100))}%`, sub: `${r.cantidad_lecturas} lecturas recibidas`,
      pct: r.cantidad_lecturas / esperadas, invertido: true });
  animarAgujas($stats);
}

function renderCharts(r) {
  if (chartCombustibleInstance) chartCombustibleInstance.destroy();
  if (chartVelocidadInstance) chartVelocidadInstance.destroy();
  if (chartTanquesInstance) { chartTanquesInstance.destroy(); chartTanquesInstance = null; }

  chartCombustibleInstance = new Chart(document.getElementById('chartCombustible'), {
    type: 'line',
    // spanGaps: entre lectura y lectura de combustible hay registros sin dato
    // (TIEMPO, eventos). Sin esto, el relleno bajaba a cero en cada hueco y
    // el gráfico se llenaba de rayas verticales que parecían caídas.
    data: { datasets: [{ label: 'Combustible (L)', data: r.serie_combustible, borderColor: '#f2a71b', backgroundColor: 'rgba(242,167,27,0.15)', fill: true, tension: 0.25, pointRadius: 0, spanGaps: true }] },
    // devicePixelRatio alto a propósito: el gráfico se ve igual en pantalla,
    // pero el canvas de fondo queda en mayor resolución — necesario para que
    // no salga borroso al exportarlo al PDF (ahí se estira para ocupar todo
    // el ancho de la hoja, y una imagen de baja resolución se nota pixelada).
    options: { maintainAspectRatio: false, devicePixelRatio: 3, scales: { x: { type: 'time' }, y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
  });

  chartVelocidadInstance = new Chart(document.getElementById('chartVelocidad'), {
    type: 'line',
    data: { datasets: [{ label: 'Velocidad km/h', data: r.serie_velocidad, borderColor: '#0d1b2e', backgroundColor: 'rgba(13,27,46,0.08)', fill: true, tension: 0.25, pointRadius: 0 }] },
    options: { maintainAspectRatio: false, devicePixelRatio: 3, scales: { x: { type: 'time' } }, plugins: { legend: { display: false } } }
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
    options: { maintainAspectRatio: false, devicePixelRatio: 3, scales: { x: { type: 'time' }, y: { beginAtZero: true } }, plugins: { legend: { display: true } } }
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
    // maxNativeZoom: en zonas rurales (fuera de ciudades grandes), Esri no
    // tiene imagen satelital cargada a partir de cierto nivel de zoom — sin
    // esto, al hacer zoom manual aparece el cartel "Map data not yet
    // available" en vez de la imagen. Con este tope, Leaflet reusa y agranda
    // la última imagen disponible en vez de pedir una que no existe.
    maxNativeZoom: 17,
  }).addTo(mapaInstance);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {
    crossOrigin: true,
    maxNativeZoom: 17,
  }).addTo(mapaInstance);
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
    crossOrigin: true,
    maxNativeZoom: 17,
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
      <td>${renderLinkGraficoTTM(ULTIMO_INFORME.equipo.nombre, a.fecha)}</td>
    </tr>
  `).join('');

  $div.innerHTML = `
    <table class="anomalias">
      <tr><th>Fecha</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th>Ubicación</th><th>Gráfico</th></tr>
      ${filas}
    </table>
  `;
}

/**
 * Arma el link al gráfico de sensor de TTM (pedido de Emiliano, 09/09/2026)
 * — para cruzar visualmente la anomalía contra los datos crudos del sensor
 * en el sistema de origen. Formato de date_range confirmado con un ejemplo
 * real de Emiliano: "YYYY-MM-DD H:MM / YYYY-MM-DD H:MM" (hora sin cero
 * adelante, minuto con cero adelante). Ventana: 1 día antes y 1 día
 * después de la anomalía, para tener contexto de sobra sin quedar
 * demasiado ancho.
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

/**
 * PDF del informe con la MISMA disposición que la pantalla:
 *   - franja negra con el logo (como el panel de TTM)
 *   - los indicadores en recuadros de 3 x 2
 *   - a la izquierda los gráficos, uno arriba del otro, todos del mismo tamaño
 *   - a la derecha el mapa en un cuadrado
 *   - abajo, a todo el ancho, la tabla de anomalías
 * Antes cada gráfico se estiraba al ancho de la hoja y las letras salían
 * gigantes; ahora cada uno ocupa su columna y mantiene su proporción.
 */
async function generarPDF() {
  if (!ULTIMO_INFORME) return;

  const $btn = document.getElementById('btnDescargarPdf');
  const textoOriginal = $btn.textContent;
  $btn.disabled = true;
  $btn.textContent = '⏳ Generando PDF…';

  try {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    const H = doc.internal.pageSize.getHeight();
    const M = 12;                 // margen
    const util = W - M * 2;       // ancho útil
    const r = ULTIMO_INFORME.reporte;

    // --- Franja negra con el logo ---
    doc.setFillColor(0, 0, 0);
    doc.rect(0, 0, W, 22, 'F');
    const logo = await imagenComoDataUrl('assets/img/logo.png');
    if (logo) {
      doc.addImage(logo.dataUrl, 'PNG', M, 5, 12 * logo.proporcion, 12, undefined, 'FAST');
    }
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text('Informe de flota', W - M, 10, { align: 'right' });
    doc.setTextColor(255, 176, 0);
    doc.setFont('helvetica', 'bold');
    doc.text(`${tipoLabel}`, W - M, 15, { align: 'right' });

    // --- Título del informe ---
    let y = 31;
    doc.setTextColor(31, 35, 41);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text(`${ULTIMO_INFORME.equipo.nombre}`, M, y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(107, 114, 128);
    doc.text(`Equipo ${ULTIMO_INFORME.equipo.id} · Período: ${formatearFecha(ULTIMO_INFORME.desde)} — ${formatearFecha(ULTIMO_INFORME.hasta)} · Generado: ${new Date().toLocaleString('es-AR')}`, M, y + 5);
    y += 11;
    if (ULTIMO_INFORME.simulado) {
      doc.setTextColor(150, 100, 20);
      doc.text('Datos SIMULADOS: la API real no estaba disponible al generar este informe.', M, y);
      y += 5;
    }

    // --- Indicadores en recuadros (3 x 2) ---
    const kpis = [
      ['Km recorridos', r.km_recorridos !== null ? `${formatNum(r.km_recorridos)} km` : '—'],
      ['Consumo promedio', r.consumo_l_100km !== null ? `${formatNum(r.consumo_l_100km)} L/100km` : '—'],
      ['Combustible consumido', `${formatNum(r.litros_consumidos_total)} L`],
      ['Litros perdidos', `${formatNum(r.litros_perdidos_anomalos)} L`],
      ['Anomalías', `${r.cantidad_anomalias}`],
      ['Lecturas recibidas', `${r.cantidad_lecturas}`],
    ];
    const cajaW = (util - 2 * 4) / 3;
    const cajaH = 15;
    kpis.forEach(([titulo, valor], i) => {
      const x = M + (i % 3) * (cajaW + 4);
      const yy = y + Math.floor(i / 3) * (cajaH + 3);
      doc.setDrawColor(230, 232, 236);
      doc.setFillColor(255, 255, 255);
      doc.roundedRect(x, yy, cajaW, cajaH, 1.5, 1.5, 'FD');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(7);
      doc.setTextColor(92, 99, 110);
      doc.text(titulo.toUpperCase(), x + 4, yy + 5.5);
      doc.setFontSize(12);
      doc.setTextColor(31, 35, 41);
      doc.text(valor, x + 4, yy + 12);
    });
    y += 2 * cajaH + 3 + 7;

    // --- Dos columnas: gráficos a la izquierda, mapa a la derecha ---
    const colIzqW = util * 0.60;
    const colDerX = M + colIzqW + 6;
    const colDerW = util - colIzqW - 6;
    const inicioColumnas = y;

    const graficos = [
      [chartCombustibleInstance, 'Nivel de combustible (litros)'],
      [chartTanquesInstance, 'Detalle por tanque'],
      [chartVelocidadInstance, 'Velocidad (km/h)'],
    ].filter(([g]) => g);

    let yIzq = inicioColumnas;
    for (const [grafico, titulo] of graficos) {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9);
      doc.setTextColor(31, 35, 41);
      doc.text(titulo, M, yIzq);
      const c = grafico.canvas;
      const alto = colIzqW * (c.height / c.width);
      // Los gráficos van en JPEG sobre fondo blanco: en PNG a alta resolución
      // el PDF pesaba 14 MB, imposible de mandar por mail.
      doc.addImage(graficoComoJpeg(c), 'JPEG', M, yIzq + 2, colIzqW, alto, undefined, 'FAST');
      yIzq += alto + 9;
    }

    let yDer = inicioColumnas;
    const $cardMapa = document.getElementById('cardMapa');
    if (mapaInstance && !$cardMapa.classList.contains('hidden')) {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9);
      doc.setTextColor(31, 35, 41);
      doc.text('Recorrido', colDerX, yDer);
      try {
        const mapaCanvas = await html2canvas(document.getElementById('mapa'), { useCORS: true, logging: false, scale: 2 });
        // Recorto al cuadrado del centro, por si el mapa en pantalla no es cuadrado.
        const lado = Math.min(mapaCanvas.width, mapaCanvas.height);
        const cuadrado = document.createElement('canvas');
        cuadrado.width = lado;
        cuadrado.height = lado;
        cuadrado.getContext('2d').drawImage(mapaCanvas, (mapaCanvas.width - lado) / 2, (mapaCanvas.height - lado) / 2, lado, lado, 0, 0, lado, lado);
        doc.addImage(cuadrado.toDataURL('image/jpeg', 0.85), 'JPEG', colDerX, yDer + 2, colDerW, colDerW, undefined, 'FAST');
      } catch (e) {
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(180, 40, 40);
        doc.text('No se pudo capturar el mapa.', colDerX, yDer + 8);
      }
      yDer += colDerW + 9;
    }

    // --- Anomalías, abajo y a todo el ancho ---
    y = Math.max(yIzq, yDer) + 2;
    if (y > H - 40) {
      doc.addPage();
      y = M + 6;
    }
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(31, 35, 41);
    doc.text('Anomalías del período', M, y);
    y += 3;

    if (r.anomalias.length === 0) {
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9.5);
      doc.setTextColor(63, 107, 76);
      doc.text('Sin anomalías detectadas en este período.', M, y + 5);
    } else {
      doc.autoTable({
        startY: y,
        margin: { left: M, right: M, top: M, bottom: 16 },
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
        styles: { fontSize: 7.5, cellPadding: 2, textColor: [43, 47, 54], lineColor: [230, 232, 236], lineWidth: 0.1 },
        headStyles: { fillColor: [43, 45, 49], textColor: [255, 255, 255], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [248, 249, 251] },
        columnStyles: { 0: { cellWidth: 24 }, 1: { cellWidth: 28 }, 3: { cellWidth: 16 }, 4: { cellWidth: 30 } },
      });
    }

    // --- Pie en todas las hojas, con número de página ---
    const paginas = doc.internal.getNumberOfPages();
    for (let i = 1; i <= paginas; i++) {
      doc.setPage(i);
      doc.setDrawColor(230, 232, 236);
      doc.line(M, H - 11, W - M, H - 11);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(7.5);
      doc.setTextColor(150, 150, 150);
      doc.text('Preciso · Módulo de informes y detección de anomalías — desarrollado por Puma Code', M, H - 7);
      doc.text(`Página ${i} de ${paginas}`, W - M, H - 7, { align: 'right' });
    }

    const nombreArchivo = `preciso_informe_${equipoId}_${tipo}_${new Date().toISOString().slice(0, 10)}.pdf`;
    doc.save(nombreArchivo);

    // Se generó y descargó el PDF -> las anomalías de este período quedan
    // "atendidas" (marcadas resueltas en la base).
    await marcarResueltas();
  } catch (e) {
    alert('No se pudo generar el PDF: ' + e.message);
  } finally {
    $btn.disabled = false;
    $btn.textContent = textoOriginal;
  }
}

/** Pasa un gráfico a JPEG con fondo blanco (el canvas es transparente y en JPEG quedaría negro). */
function graficoComoJpeg(canvas) {
  const c = document.createElement('canvas');
  c.width = canvas.width;
  c.height = canvas.height;
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.drawImage(canvas, 0, 0);
  return c.toDataURL('image/jpeg', 0.85);
}

/** Carga una imagen (el logo) y la devuelve como dataURL + su proporción ancho/alto. */
function imagenComoDataUrl(url) {
  return new Promise(resolve => {
    const img = new Image();
    img.onload = () => {
      const c = document.createElement('canvas');
      c.width = img.naturalWidth;
      c.height = img.naturalHeight;
      c.getContext('2d').drawImage(img, 0, 0);
      resolve({ dataUrl: c.toDataURL('image/png'), proporcion: img.naturalWidth / img.naturalHeight });
    };
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

async function marcarResueltas() {
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
  } catch (e) {
    // Si esto falla, no rompe la descarga del PDF (que ya se hizo) — la
    // próxima vez que se genere un informe se vuelve a intentar.
  }
}


cargarInforme();