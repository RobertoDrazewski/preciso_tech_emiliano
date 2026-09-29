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

/** Etiqueta de estado + número de ticket. */
function renderEstadoTicket(a) {
  const e = ESTADOS_TICKET[a.estado_revision || 'pendiente'] || ESTADOS_TICKET.pendiente;
  return `<span class="tk-estado ${a.estado_revision || 'pendiente'}"><i class="fa-solid ${e.icono}"></i> ${e.label}</span>
          <div class="tk-numero">Ticket #${a.id}</div>`;
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
  if (estado === 'pendiente') {
    return b('notificar', 'Notificar', 'fa-paper-plane', 'principal') + b('rechazar', 'Rechazar', 'fa-ban', 'secundario');
  }
  if (estado === 'notificada') {
    return b('informar', 'Informada', 'fa-circle-check', 'principal') + b('rechazar', 'Rechazar', 'fa-ban', 'secundario');
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
    btn.addEventListener('mousedown', () => { $root.dataset.accionEnCurso = btn.dataset.id; });
    btn.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const id = Number(btn.dataset.id);
      const $obs = $root.querySelector(`.tk-obs[data-id="${id}"]`);
      window.revisarAnomalia(id, btn.dataset.accion, $obs ? $obs.value : undefined);
    });
  });
  $root.querySelectorAll('.tk-obs').forEach($t => {
    const original = $t.value;
    $t.addEventListener('click', ev => ev.stopPropagation());
    $t.addEventListener('blur', async () => {
      if ($t.value === original) return;
      if ($root.dataset.accionEnCurso === $t.dataset.id) { delete $root.dataset.accionEnCurso; return; }
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
