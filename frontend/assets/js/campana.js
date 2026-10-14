/**
 * Campanita de notificaciones (arriba a la derecha, en todas las pantallas).
 *
 * Muestra cuántos tickets están "Por notificar". Al tocarla se abre la
 * lista de los últimos, y desde ahí mismo se pueden NOTIFICAR (avisar a
 * operaciones para que revisen el móvil) o RECHAZAR (falsa alarma). El
 * ciclo completo se maneja en la pantalla de Tickets.
 *
 * Cada vez que se valida o descarta algo, aviso con el evento
 * "preciso:revision" para que la pantalla que esté abierta (por ejemplo
 * Anomalías) se actualice sola.
 *
 * Nota: hoy el sistema no tiene usuarios ni login, así que no queda
 * registrado QUIÉN revisó cada anomalía, solo cuándo y el comentario.
 */
(function () {
  const $raiz = document.getElementById('campana');
  if (!$raiz) return;

  const API = () => window.PRECISO_CONFIG.API_BASE;
  let abierta = false;

  const esc = (t) => { const d = document.createElement('div'); d.textContent = t ?? ''; return d.innerHTML; };
  const fecha = (iso) => new Date(iso).toLocaleString('es-AR', { timeZone: ZONA_HORARIA, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const corto = (t, n = 140) => (t && t.length > n ? t.slice(0, n - 1) + '…' : t || '');

  $raiz.innerHTML = `
    <button class="campana-boton" type="button" title="Tickets por notificar">
      <i class="fa-solid fa-bell"></i><span class="campana-numero" hidden></span>
    </button>
    <div class="campana-panel" hidden></div>`;
  const $boton = $raiz.querySelector('.campana-boton');
  const $numero = $raiz.querySelector('.campana-numero');
  const $panel = $raiz.querySelector('.campana-panel');

  async function cargar() {
    try {
      const resp = await fetch(`${API()}/revision.php?limite=15`);
      const data = await resp.json();
      if (!resp.ok) return;
      pintarNumero(data.pendientes || 0);
      if (abierta) pintarPanel(data);
    } catch (e) {
      // Si falla, la campanita queda como estaba: no rompe la pantalla.
    }
  }

  function pintarPanel(data) {
    const items = (data.anomalias || []).map(a => `
      <div class="campana-item" data-id="${a.id}">
        <div class="ci-arriba">
          <span class="ci-equipo">${esc(a.equipo_nombre || a.equipo)}</span>
          <span class="ci-fecha">${fecha(a.fecha)}</span>
        </div>
        <span class="tag ${esc(a.tipo)}">${esc(String(a.tipo).replace(/_/g, ' '))}</span>
        <div class="ci-detalle">${esc(corto(a.detalle))}</div>
        <div class="rev-botones">
          <button class="rev-btn validar" data-accion="notificar"><i class="fa-solid fa-paper-plane"></i> Notificar</button>
          <button class="rev-btn descartar" data-accion="rechazar"><i class="fa-solid fa-ban"></i> Rechazar</button>
        </div>
      </div>`).join('');
    $panel.innerHTML = `
      <header>
        <span><i class="fa-solid fa-bell"></i> Por notificar (${data.pendientes || 0})</span>
        <a href="tickets.html">Ver tickets →</a>
      </header>
      ${items || '<div class="campana-vacia">No hay tickets por notificar. 👍</div>'}`;
  }

  /**
   * Cambia el estado de un ticket. Si no viene observación (por ejemplo,
   * desde la campanita) y la acción es notificar o rechazar, la pido: es lo
   * que después ve operaciones y queda en el historial.
   */
  async function revisar(id, accion, observacion) {
    if (observacion === undefined && (accion === 'notificar' || accion === 'rechazar')) {
      const pregunta = accion === 'notificar'
        ? 'Observación para operaciones (qué corroboraste, qué hay que revisar):'
        : '¿Por qué es una falsa alarma?';
      observacion = prompt(pregunta, '');
      if (observacion === null) return; // tocó Cancelar
    }
    // Al instante: la pantalla se actualiza YA y el guardado sigue de fondo
    // (14/10/2026). Antes cada clic esperaba dos idas y vueltas al servidor
    // y recién después se redibujaba: de ahí los segundos de espera.
    ajustarNumeroLocal(id, accion);
    window.dispatchEvent(new CustomEvent('preciso:revision', { detail: { id, accion, observacion } }));
    try {
      const resp = await fetch(`${API()}/revision.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, accion, observacion }),
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok) throw new Error(data.error || 'Error');
      if (typeof data.pendientes === 'number') pintarNumero(data.pendientes);
    } catch (e) {
      alert('No se pudo guardar el ticket: ' + e.message + '. Recargo la lista.');
      window.dispatchEvent(new CustomEvent('preciso:revision-error', { detail: { id, accion } }));
      cargar();
    }
  }

  let numeroActual = 0;
  function pintarNumero(n) {
    numeroActual = Math.max(0, n);
    $numero.hidden = numeroActual === 0;
    $numero.textContent = numeroActual > 99 ? '99+' : numeroActual;
  }

  /** Ajusta el número de la campanita y saca el ticket del desplegable, sin esperar al servidor. */
  function ajustarNumeroLocal(id, accion) {
    const $item = $panel.querySelector(`.campana-item[data-id="${id}"]`);
    if ($item && accion !== 'observacion' && accion !== 'cliente') $item.remove();
    if (accion === 'notificar' || accion === 'rechazar') {
      // Solo baja si el ticket estaba "por notificar" (desde la campanita
      // siempre; desde otras pantallas lo confirma la respuesta del servidor).
      if ($item) pintarNumero(numeroActual - 1);
    }
  }

  $boton.addEventListener('click', async (ev) => {
    ev.stopPropagation();
    abierta = !abierta;
    $panel.hidden = !abierta;
    if (abierta) {
      $panel.innerHTML = '<div class="campana-vacia">Cargando…</div>';
      await cargar();
    }
  });
  $panel.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const b = ev.target.closest('.rev-btn');
    if (b) revisar(Number(b.closest('.campana-item').dataset.id), b.dataset.accion);
  });
  document.addEventListener('click', () => { abierta = false; $panel.hidden = true; });

  // Exponer para que otras pantallas usen la misma lógica de revisión.
  window.revisarAnomalia = revisar;
  window.addEventListener('preciso:revision-externa', cargar);

  cargar();
  setInterval(cargar, 60000);
})();
