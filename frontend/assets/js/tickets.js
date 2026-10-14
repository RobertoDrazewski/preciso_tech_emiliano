/**
 * Pantalla de Tickets (reemplaza a la vieja "Por revisar").
 *
 * Arriba, cuatro indicadores con barras: cuántos tickets hay por notificar,
 * notificados, informados y rechazados (y qué parte del total es cada uno).
 * Tocando uno, o las pestañas, se filtra por ese estado. En la tabla, cada
 * ticket tiene su observación editable, sus botones según el estado y su
 * historial.
 */
let TICKETS = [];
let ESTADO_ACTIVO = 'pendiente';
let ULTIMOS_STATS = {};

/** Recuenta los estados con lo que hay en memoria (después de una acción). */
function statsLocales() {
  const c = { pendiente: 0, notificada: 0, informada: 0, rechazada: 0 };
  TICKETS.forEach(a => { c[a.estado_revision || 'pendiente']++; });
  return { ...ULTIMOS_STATS, pendientes: c.pendiente, notificadas: c.notificada, informadas: c.informada, rechazadas: c.rechazada };
}

async function initTickets() {
  await cargarFlotas();
  document.getElementById('tkDias').addEventListener('change', cargarTickets);
  document.getElementById('tkCliente').addEventListener('change', cargarTickets);
  document.getElementById('tkBuscar').addEventListener('input', pintarTabla);
  // Al validar/rechazar/etc. actualizo en memoria y redibujo (sin volver a
  // pedir toda la lista al servidor: eso era lo que tardaba segundos).
  // (14/10/2026) Ya no redibujo la tabla entera (hasta 5000 filas): solo la
  // fila tocada. Si el servidor devuelve error, recargo todo.
  window.addEventListener('preciso:revision', (ev) => {
    const a = aplicarAccionLocal(ev.detail || {});
    if (!a) { cargarTickets(); return; }
    const st = statsLocales();
    pintarIndicadores(st);
    pintarPestanas(st);
    actualizarFila(a);
  });
  window.addEventListener('preciso:revision-error', cargarTickets);
  const desdeUrl = new URLSearchParams(location.search).get('estado');
  if (desdeUrl && ESTADOS_TICKET[desdeUrl]) ESTADO_ACTIVO = desdeUrl;
  cargarTickets();
}

async function cargarFlotas() {
  try {
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/equipos.php`);
    const data = await r.json();
    const flotas = [...new Set((data.equipos || []).map(e => e.cliente).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
    const $s = document.getElementById('tkCliente');
    flotas.forEach(f => { const o = document.createElement('option'); o.value = f; o.textContent = f; $s.appendChild(o); });
  } catch (e) { /* sin lista de flotas igual se puede usar */ }
}

async function cargarTickets() {
  const params = new URLSearchParams({ dias: document.getElementById('tkDias').value, revision: 'todas' });
  const cliente = document.getElementById('tkCliente').value;
  if (cliente) params.set('cliente', cliente);
  try {
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/anomalias.php?${params}`);
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'Error');
    TICKETS = data.anomalias;
    registrarAnomalias(TICKETS);
    ULTIMOS_STATS = data.stats;
    document.getElementById('tkLoading').classList.add('hidden');
    document.getElementById('tkContenido').classList.remove('hidden');
    pintarIndicadores(data.stats);
    pintarPestanas(data.stats);
    pintarTabla();
  } catch (e) {
    document.getElementById('tkLoading').textContent = 'No se pudieron cargar los tickets: ' + e.message;
  }
}

/** Los cuatro indicadores del ciclo, con barras. Tocar uno filtra por ese estado. */
function pintarIndicadores(st) {
  const total = (st.pendientes || 0) + (st.notificadas || 0) + (st.informadas || 0) + (st.rechazadas || 0);
  const pct = n => (total ? n / total : 0);
  const $s = document.getElementById('tkStats');
  $s.innerHTML =
    gauge({ datos: { estado: 'pendiente' }, titulo: 'Por notificar', icono: 'fa-inbox', valor: numeroAR(st.pendientes),
      sub: `${Math.round(pct(st.pendientes) * 100)}% del total · falta notificarlos`, pct: pct(st.pendientes) }) +
    gauge({ datos: { estado: 'notificada' }, titulo: 'Notificadas', icono: 'fa-paper-plane', valor: numeroAR(st.notificadas),
      sub: 'avisadas a operaciones, esperando revisión', pct: pct(st.notificadas), invertido: true }) +
    gauge({ datos: { estado: 'informada' }, titulo: 'Informadas', icono: 'fa-circle-check', valor: numeroAR(st.informadas),
      sub: `cerradas · ${numeroAR(st.litros_confirmados, 1)} L de merma confirmada`, pct: pct(st.informadas), invertido: true }) +
    gauge({ datos: { estado: 'rechazada' }, titulo: 'Rechazadas', icono: 'fa-ban', valor: numeroAR(st.rechazadas),
      sub: 'falsas alarmas', pct: pct(st.rechazadas) });
  animarAgujas($s);
  $s.querySelectorAll('.kpi-card[data-estado]').forEach(c => {
    if (c.dataset.estado === ESTADO_ACTIVO) c.classList.add('seleccionada');
    c.addEventListener('click', () => { ESTADO_ACTIVO = c.dataset.estado; cargarTickets(); });
  });
}

function pintarPestanas(st) {
  const conteo = { pendiente: st.pendientes, notificada: st.notificadas, informada: st.informadas, rechazada: st.rechazadas };
  const total = Object.values(conteo).reduce((a, b) => a + (b || 0), 0);
  const pest = Object.entries(ESTADOS_TICKET).map(([k, e]) =>
    `<button class="tk-pestana${k === ESTADO_ACTIVO ? ' activa' : ''}" data-estado="${k}"><i class="fa-solid ${e.icono}"></i> ${e.label} <span>${numeroAR(conteo[k])}</span></button>`
  ).join('') + `<button class="tk-pestana${ESTADO_ACTIVO === 'todas' ? ' activa' : ''}" data-estado="todas">Todas <span>${numeroAR(total)}</span></button>`;
  const $p = document.getElementById('tkPestanas');
  $p.innerHTML = pest;
  $p.querySelectorAll('.tk-pestana').forEach(b => b.addEventListener('click', () => { ESTADO_ACTIVO = b.dataset.estado; cargarTickets(); }));
}

function pintarTabla() {
  const q = document.getElementById('tkBuscar').value.trim().toLowerCase();
  const lista = TICKETS.filter(a => (ESTADO_ACTIVO === 'todas' || (a.estado_revision || 'pendiente') === ESTADO_ACTIVO))
    .filter(a => !q || [a.equipo_nombre, a.equipo_cliente, String(a.id), a.comentario_revision, a.detalle].some(t => (t || '').toLowerCase().includes(q)));

  const $t = document.getElementById('tkTabla');
  if (lista.length === 0) {
    $t.innerHTML = '<div class="empty-state">No hay tickets en este estado. 👍</div>';
    return;
  }
  const fecha = iso => new Date(iso).toLocaleString('es-AR', { timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  $t.innerHTML = `
    <table class="anomalias tk-tabla">
      <tr><th>Ticket</th><th>Fecha</th><th>Equipo</th><th>Tipo</th><th>Detalle</th><th>Litros</th><th style="min-width:220px">Observación del operador</th><th>Acciones</th></tr>
      ${lista.map(a => `
        <tr data-id="${a.id}">
          <td>${renderEstadoTicket(a)}</td>
          <td>${fecha(a.fecha)}</td>
          <td><a href="informe.html?equipo=${encodeURIComponent(a.equipo)}&tipo=semanal">${escTicket(a.equipo_nombre)}</a><div class="tk-numero">${escTicket(a.equipo_cliente || '')}</div></td>
          <td><span class="tag ${escTicket(a.tipo)}">${escTicket(a.tipo.replace(/_/g, ' '))}</span></td>
          <td>${escTicket(a.detalle)}</td>
          <td>${Number(a.litros_perdidos).toFixed(1)} L</td>
          <td>${renderObservacion(a)}</td>
          <td><div class="rev-botones">${renderAccionesTicket(a)}</div>
              <button class="tk-historial" data-id="${a.id}"><i class="fa-solid fa-clock-rotate-left"></i> Historial</button>
              <div class="tk-historial-lista" data-id="${a.id}" hidden></div></td>
        </tr>`).join('')}
    </table>`;
  engancharTickets($t);
  $t.querySelectorAll('.tk-historial').forEach(b => b.addEventListener('click', () => verHistorial(b.dataset.id)));
}

/** Actualiza una sola fila después de una acción (o la saca si ya no corresponde a la pestaña). */
function actualizarFila(a) {
  const $fila = document.querySelector(`#tkTabla tr[data-id="${a.id}"]`);
  if (!$fila) return;
  const estado = a.estado_revision || 'pendiente';
  if (ESTADO_ACTIVO !== 'todas' && estado !== ESTADO_ACTIVO) {
    $fila.remove();
    if (!document.querySelector('#tkTabla tr[data-id]')) {
      document.getElementById('tkTabla').innerHTML = '<div class="empty-state">No hay tickets en este estado. 👍</div>';
    }
    return;
  }
  const $c = $fila.children;
  $c[0].innerHTML = renderEstadoTicket(a);
  const $acciones = $c[$c.length - 1].querySelector('.rev-botones');
  $acciones.innerHTML = renderAccionesTicket(a);
  engancharTickets($acciones);
}

/** Muestra (o esconde) el historial de un ticket: cada cambio de estado u observación con su fecha. */
async function verHistorial(id) {
  const $l = document.querySelector(`.tk-historial-lista[data-id="${id}"]`);
  if (!$l.hidden) { $l.hidden = true; return; }
  $l.hidden = false;
  $l.textContent = 'Cargando…';
  try {
    const r = await fetch(`${window.PRECISO_CONFIG.API_BASE}/revision.php?historial=${id}`);
    const { eventos } = await r.json();
    $l.innerHTML = eventos.length === 0 ? '<div>Sin movimientos todavía.</div>' : eventos.map(e => `
      <div class="tk-evento"><b>${new Date(e.fecha).toLocaleString('es-AR', { timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</b>
        ${e.estado ? (ESTADOS_TICKET[e.estado]?.label || e.estado) : 'Observación editada'}${e.observacion ? ` — ${escTicket(e.observacion)}` : ''}</div>`).join('');
  } catch (e) {
    $l.textContent = 'No se pudo cargar el historial.';
  }
}

initTickets();
