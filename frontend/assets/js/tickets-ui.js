/**
 * Piezas del sistema de tickets que comparten todas las pantallas
 * (Anomalías, Tickets y la campanita), para que se comporten igual.
 *
 * Ciclo de un ticket (explicado también en Storage::revisarAnomalia):
 *   Por notificar -> Notificada (se avisó a operaciones para revisar el móvil)
 *                 -> Informada (ya se revisó: ticket cerrado)
 *   o Rechazada (falsa alarma). Cualquiera se puede reabrir.
 *
 * La observación del operador se escribe al lado de cada anomalía, se
 * guarda sola al salir del campo, se puede cambiar cuando se quiera y queda
 * en el historial del ticket.
 */
const ESTADOS_TICKET = {
  pendiente:  { label: 'Por notificar', icono: 'fa-inbox' },
  notificada: { label: 'Notificada',    icono: 'fa-paper-plane' },
  informada:  { label: 'Informada',     icono: 'fa-circle-check' },
  rechazada:  { label: 'Rechazada',     icono: 'fa-ban' },
};

function escTicket(t) { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; }

// Todas las anomalías que se dibujaron en pantalla, por id: las necesito
// para armar el PDF del cliente y para actualizar una fila sin recargar todo.
const ANOMALIAS_POR_ID = {};
function registrarAnomalias(lista) {
  lista.forEach(a => { ANOMALIAS_POR_ID[a.id] = a; });
}

/** Etiqueta de estado + número de ticket (+ aviso si ya se le mandó el PDF al cliente). */
function renderEstadoTicket(a) {
  const e = ESTADOS_TICKET[a.estado_revision || 'pendiente'] || ESTADOS_TICKET.pendiente;
  const cliente = a.cliente_notificado_en
    ? `<div class="tk-cliente-ok" title="${escTicket(new Date(a.cliente_notificado_en).toLocaleString('es-AR', { timeZone: ZONA_HORARIA }))}"><i class="fa-solid fa-envelope-circle-check"></i> Cliente notificado</div>`
    : '';
  return `<span class="tk-estado ${a.estado_revision || 'pendiente'}"><i class="fa-solid ${e.icono}"></i> ${e.label}</span>
          <div class="tk-numero">Ticket #${a.id}</div>${cliente}`;
}

/** Campo de observación editable (se guarda solo al salir). */
function renderObservacion(a) {
  return `<textarea class="tk-obs" data-id="${a.id}" rows="2" placeholder="Observación del operador…">${escTicket(a.comentario_revision || '')}</textarea>
          <div class="tk-obs-estado" data-id="${a.id}"></div>`;
}

/** Botones según en qué parte del ciclo está el ticket. */
function renderAccionesTicket(a) {
  const estado = a.estado_revision || 'pendiente';
  const b = (accion, texto, icono, clase) =>
    `<button class="tk-btn ${clase}" data-accion="${accion}" data-id="${a.id}" title="${texto}"><i class="fa-solid ${icono}"></i> ${texto}</button>`;
  // "Notificar al cliente" le manda el PDF de la anomalía por mail (por ahora
  // en modo prueba: llega al correo de prueba, no al cliente).
  const cliente = b('cliente', 'Notificar al cliente', 'fa-envelope', 'cliente');
  if (estado === 'pendiente') {
    return b('notificar', 'Notificar', 'fa-paper-plane', 'principal') + cliente + b('rechazar', 'Rechazar', 'fa-ban', 'secundario');
  }
  if (estado === 'notificada') {
    return b('informar', 'Informada', 'fa-circle-check', 'principal') + cliente + b('rechazar', 'Rechazar', 'fa-ban', 'secundario');
  }
  return b('reabrir', 'Reabrir', 'fa-rotate-left', 'secundario');
}

/**
 * Engancha los botones y las observaciones dentro de un contenedor.
 * Al notificar / informar / rechazar mando la observación que esté escrita
 * en esa fila; si está vacía y la acción es rechazar o notificar, la pido.
 */
function engancharTickets($root) {
  $root.querySelectorAll('.tk-btn').forEach(btn => {
    // Si el operador escribe y toca un botón directo, el botón ya manda la
    // observación: marco que hay una acción en curso para que el guardado
    // automático no la registre dos veces en el historial.
    btn.addEventListener('mousedown', () => { window.__accionEnCurso = btn.dataset.id; });
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const id = Number(btn.dataset.id);
      const $obs = $root.querySelector(`.tk-obs[data-id="${id}"]`) || document.querySelector(`.tk-obs[data-id="${id}"]`);
      if (btn.dataset.accion === 'cliente') {
        notificarCliente(id, btn);
        return;
      }
      window.revisarAnomalia(id, btn.dataset.accion, $obs ? $obs.value : undefined);
    });
  });
  $root.querySelectorAll('.tk-obs').forEach($t => {
    const original = $t.value;
    $t.addEventListener('click', ev => ev.stopPropagation());
    $t.addEventListener('blur', async () => {
      if ($t.value === original) return;
      if (window.__accionEnCurso === $t.dataset.id) { window.__accionEnCurso = null; return; }
      const $estado = $root.querySelector(`.tk-obs-estado[data-id="${$t.dataset.id}"]`);
      try {
        const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/revision.php`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: Number($t.dataset.id), accion: 'observacion', observacion: $t.value }),
        });
        if (!r.ok) throw new Error();
        if ($estado) { $estado.textContent = 'Guardado ✓'; setTimeout(() => ($estado.textContent = ''), 2500); }
      } catch (e) {
        if ($estado) $estado.textContent = 'No se pudo guardar';
      }
    });
  });
}


/**
 * Arma el PDF de UNA anomalía (con el mismo estilo del informe: franja negra
 * con el logo) y se lo manda al backend para que lo envíe por mail.
 */
async function pdfDeAnomalia(a) {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const W = doc.internal.pageSize.getWidth();
  const M = 15;
  doc.setFillColor(0, 0, 0);
  doc.rect(0, 0, W, 24, 'F');
  try {
    const logo = await new Promise((ok, mal) => {
      const img = new Image();
      img.onload = () => { const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight; c.getContext('2d').drawImage(img, 0, 0); ok({ url: c.toDataURL('image/png'), p: img.naturalWidth / img.naturalHeight }); };
      img.onerror = mal; img.src = 'assets/img/logo.png';
    });
    doc.addImage(logo.url, 'PNG', M, 6, 12 * logo.p, 12, undefined, 'FAST'); // comprimido: sin esto el PDF pesaba 1,2 MB
  } catch (e) { /* sin logo igual sale */ }
  doc.setTextColor(255, 176, 0); doc.setFont('helvetica', 'bold'); doc.setFontSize(10);
  doc.text('Aviso de anomalía', W - M, 14, { align: 'right' });

  doc.setTextColor(31, 35, 41); doc.setFontSize(16);
  doc.text(a.equipo_nombre || a.equipo, M, 38);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(107, 114, 128);
  doc.text(`${a.equipo_cliente || ''}  ·  Ticket #${a.id}`, M, 44);

  const filas = [
    ['Tipo', String(a.tipo).replace(/_/g, ' ')],
    ['Fecha y hora', new Date(a.fecha).toLocaleString('es-AR', { timeZone: ZONA_HORARIA })],
    ['Detalle', a.detalle || ''],
    ['Litros', `${Number(a.litros_perdidos || 0).toFixed(1)} L`],
    ['Ubicación', a.lat != null && a.lng != null ? `${Number(a.lat).toFixed(5)}, ${Number(a.lng).toFixed(5)}  (https://maps.google.com/?q=${a.lat},${a.lng})` : '—'],
    ['Observación', a.comentario_revision || '—'],
  ];
  doc.autoTable({
    startY: 50, margin: { left: M, right: M }, body: filas, theme: 'grid',
    styles: { fontSize: 10, cellPadding: 3, textColor: [43, 47, 54], lineColor: [230, 232, 236] },
    columnStyles: { 0: { cellWidth: 35, fontStyle: 'bold', fillColor: [248, 249, 251] } },
  });
  doc.setFontSize(8); doc.setTextColor(150, 150, 150);
  doc.text('Preciso · Gestión de flotas — informe generado automáticamente por el módulo de detección de anomalías', M, 287);
  return doc.output('datauristring').split(',')[1];
}

async function notificarCliente(id, btn) {
  const a = ANOMALIAS_POR_ID[id];
  if (!a) { alert('No encontré los datos de esta anomalía. Recargá la página.'); return; }
  if (!confirm(`¿Enviar el PDF de esta anomalía al cliente${a.equipo_cliente ? ' (' + a.equipo_cliente + ')' : ''}?`)) return;
  const textoOriginal = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando…';
  try {
    const pdf = await pdfDeAnomalia(a);
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/notificar_cliente.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, pdf_base64: pdf }),
    });
    const d = await r.json();
    if (!r.ok) throw new Error(d.error || 'Error');
    a.cliente_notificado_en = new Date().toISOString();
    alert((d.modo_prueba ? 'MODO PRUEBA — ' : '') + d.detalle);
    window.dispatchEvent(new CustomEvent('preciso:revision', { detail: { id, accion: 'cliente' } }));
  } catch (e) {
    alert('No se pudo notificar al cliente: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = textoOriginal;
  }
}

/**
 * Después de una acción, actualizo SOLO esa anomalía en memoria (en vez de
 * recargar toda la lista desde el servidor, que era lo que hacía tardar).
 */
function aplicarAccionLocal(detalle) {
  const a = ANOMALIAS_POR_ID[detalle.id];
  if (!a) return null;
  const estados = { notificar: 'notificada', informar: 'informada', rechazar: 'rechazada', reabrir: 'pendiente' };
  if (estados[detalle.accion]) a.estado_revision = estados[detalle.accion];
  if (detalle.observacion !== undefined && detalle.observacion !== null && detalle.observacion !== '') a.comentario_revision = detalle.observacion;
  return a;
}
