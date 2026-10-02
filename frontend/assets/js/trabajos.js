/**
 * Recálculo de anomalías en segundo plano (pedido de Emiliano, 02/10/2026).
 *
 * Cuando se cambia un umbral, el botón "Recalcular" de la ventana de umbrales
 * arranca un recálculo de ese tipo. El servidor trabaja de a tandas y guarda
 * el avance en la base; este archivo, que está en TODAS las pantallas, va
 * pidiendo las tandas y muestra una barrita abajo a la derecha con el
 * porcentaje. Por eso:
 *   - si se cierra la ventana de umbrales, sigue;
 *   - si se cambia de pantalla, la pantalla nueva lo retoma donde iba;
 *   - cuando termina, avisa con un cartel (y una notificación del navegador
 *     si se le dio permiso) con cuántas se borraron y cuántas se detectaron.
 */
(function () {
  const API = () => window.PRECISO_CONFIG.API_BASE;
  const TAB = Math.random().toString(36).slice(2); // para que dos pestañas no procesen lo mismo
  let corriendo = false;

  const $w = document.createElement('div');
  $w.className = 'trabajo-widget';
  $w.hidden = true;
  document.body.appendChild($w);

  const nombreTipo = t => String(t || '').replace(/_/g, ' ');

  function pintar(e) {
    $w.hidden = false;
    $w.innerHTML = `
      <div class="tw-cab"><i class="fa-solid fa-rotate fa-spin"></i> Recalculando: <b>${nombreTipo(e.tipo)}</b></div>
      <div class="tw-barra"><span style="width:${e.porcentaje || 0}%"></span></div>
      <div class="tw-pie">${e.porcentaje || 0}% · ${e.procesados}/${e.total} equipos · ${e.borradas} quitadas · ${e.creadas} detectadas
        <button type="button" class="tw-cancelar">Cancelar</button></div>`;
    $w.querySelector('.tw-cancelar').onclick = cancelar;
  }

  function avisarFin(e) {
    $w.hidden = true;
    const saldo = e.creadas - e.borradas;
    const texto = e.cancelado
      ? `Recálculo de ${nombreTipo(e.tipo)} cancelado. Hasta ahí se quitaron ${e.borradas} y se detectaron ${e.creadas}.`
      : `Recálculo de ${nombreTipo(e.tipo)} terminado: se quitaron ${e.borradas} anomalías por notificar y se detectaron ${e.creadas} con los umbrales nuevos `
        + `(${saldo === 0 ? 'quedaron igual' : saldo > 0 ? `${saldo} más` : `${-saldo} menos`}). Los tickets ya trabajados no se tocaron.`;
    const $t = document.createElement('div');
    $t.className = 'trabajo-toast';
    $t.innerHTML = `<i class="fa-solid fa-circle-check"></i><div>${texto}</div><button type="button">&times;</button>`;
    $t.querySelector('button').onclick = () => $t.remove();
    document.body.appendChild($t);
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('Preciso · Recálculo terminado', { body: texto });
    }
    window.dispatchEvent(new CustomEvent('preciso:recalculo-terminado', { detail: e }));
  }

  async function post(cuerpo) {
    const r = await fetch(`${API()}/recalcular.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo),
    });
    const d = await r.json();
    if (!r.ok) throw new Error(d.error || 'Error');
    return d;
  }

  /** Va pidiendo tandas hasta que termine. Si otra pestaña está trabajando, solo muestra el avance. */
  async function seguir() {
    if (corriendo) return;
    corriendo = true;
    let errores = 0;
    try {
      while (true) {
        let e;
        try {
          e = await post({ accion: 'paso', tab: TAB });
          errores = 0;
        } catch (err) {
          if (++errores > 5) { alert('El recálculo se interrumpió: ' + err.message + '. Se retoma al recargar la página.'); return; }
          await new Promise(r => setTimeout(r, 3000));
          continue;
        }
        if (!e.activo) { if (e.terminado) avisarFin(e); return; }
        pintar(e);
        await new Promise(r => setTimeout(r, e.ocupado ? 4000 : 150));
      }
    } finally {
      corriendo = false;
    }
  }

  async function cancelar() {
    if (!confirm('¿Cancelar el recálculo? Lo que ya se procesó queda como está.')) return;
    try { await post({ accion: 'cancelar' }); } catch (e) { /* nada */ }
  }

  /** Lo llama la ventana de umbrales. */
  window.iniciarRecalculo = async function (tipo, dias = 30) {
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission();
    }
    const e = await post({ accion: 'iniciar', tipo, dias });
    pintar(e);
    seguir();
    return e;
  };

  // Al abrir cualquier pantalla: si hay un recálculo a medias, lo retomo.
  fetch(`${API()}/recalcular.php`).then(r => r.json()).then(e => { if (e.activo) { pintar(e); seguir(); } }).catch(() => {});
})();
