/**
 * Ingreso al panel (14/10/2026). Va en todas las pantallas, justo después de
 * config.php y antes que cualquier otro script.
 *
 * - Le agrega el pase ("Authorization: Bearer ...") a todos los pedidos que
 *   van al backend, sin tener que tocar cada pantalla.
 * - Si el backend contesta 401 (sin pase o vencido), muestra la ventana de
 *   ingreso. Al entrar, recarga la pantalla.
 * - Agrega un botón "Salir" arriba a la derecha.
 *
 * Si el backend no tiene clave configurada (PANEL_CLAVE), no pide nada.
 * El pase dura una semana y queda guardado en este navegador.
 */
(function () {
  const CLAVE = 'preciso_token';
  const API = () => window.PRECISO_CONFIG.API_BASE;
  const leer = () => { try { return localStorage.getItem(CLAVE) || ''; } catch (e) { return ''; } };
  const guardar = (t) => { try { t ? localStorage.setItem(CLAVE, t) : localStorage.removeItem(CLAVE); } catch (e) { /* sin almacenamiento */ } };
  const fetchOriginal = window.fetch.bind(window);
  let pantallaVisible = false;

  window.fetch = function (input, init = {}) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!url.startsWith(API())) return fetchOriginal(input, init);
    const headers = new Headers(init.headers || {});
    const token = leer();
    if (token) headers.set('Authorization', 'Bearer ' + token);
    return fetchOriginal(input, { ...init, headers }).then((r) => {
      if (r.status === 401 && !url.includes('/login.php')) {
        guardar('');
        mostrarIngreso();
      }
      return r;
    });
  };

  const estilos = `
    .ingreso-fondo{position:fixed;inset:0;background:rgba(13,27,46,.92);display:flex;align-items:center;justify-content:center;z-index:99999;padding:16px}
    .ingreso-caja{background:#fff;border-radius:12px;padding:28px 26px;width:100%;max-width:360px;font-family:Nunito,Arial,sans-serif;box-shadow:0 20px 50px rgba(0,0,0,.35)}
    .ingreso-caja img{height:34px;display:block;margin:0 auto 14px;background:#000;padding:6px 10px;border-radius:6px}
    .ingreso-caja h2{margin:0 0 4px;font-size:19px;text-align:center;color:#1f2329}
    .ingreso-caja p{margin:0 0 18px;font-size:13px;text-align:center;color:#6b7280}
    .ingreso-caja label{display:block;font-size:12.5px;font-weight:700;color:#374151;margin:10px 0 4px}
    .ingreso-caja input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px}
    .ingreso-caja button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:8px;background:#ffb000;color:#111;font-weight:800;font-size:14px;cursor:pointer}
    .ingreso-caja button:disabled{opacity:.6;cursor:wait}
    .ingreso-error{color:#c0392b;font-size:13px;margin-top:10px;text-align:center;min-height:16px}
    .boton-salir{background:none;border:1px solid rgba(255,255,255,.35);color:inherit;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;margin-left:8px}`;

  function mostrarIngreso() {
    if (pantallaVisible) return;
    pantallaVisible = true;
    const st = document.createElement('style');
    st.textContent = estilos;
    document.head.appendChild(st);
    const $f = document.createElement('div');
    $f.className = 'ingreso-fondo';
    $f.innerHTML = `
      <form class="ingreso-caja" autocomplete="on">
        <img src="assets/img/logo.png" alt="Preciso">
        <h2>Módulo de anomalías</h2>
        <p>Ingresá con tu usuario y clave.</p>
        <label for="ingUsuario">Usuario</label>
        <input id="ingUsuario" name="username" autocomplete="username" required>
        <label for="ingClave">Clave</label>
        <input id="ingClave" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Ingresar</button>
        <div class="ingreso-error"></div>
      </form>`;
    document.body.appendChild($f);
    const $form = $f.querySelector('form');
    const $err = $f.querySelector('.ingreso-error');
    $f.querySelector('#ingUsuario').focus();
    $form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const $b = $form.querySelector('button');
      $b.disabled = true;
      $err.textContent = '';
      try {
        const r = await fetchOriginal(`${API()}/login.php`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ usuario: $form.username.value, clave: $form.password.value }),
        });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'No se pudo ingresar.');
        guardar(d.token || '');
        location.reload();
      } catch (e) {
        $err.textContent = e.message;
        $b.disabled = false;
      }
    });
  }

  function botonSalir() {
    const $zona = document.querySelector('.app-header-derecha');
    if (!$zona || !leer()) return;
    const $b = document.createElement('button');
    $b.type = 'button';
    $b.className = 'boton-salir';
    $b.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Salir';
    $b.addEventListener('click', () => { guardar(''); location.reload(); });
    $zona.appendChild($b);
    if (!pantallaVisible) {
      const st = document.createElement('style');
      st.textContent = estilos;
      document.head.appendChild(st);
    }
  }

  // Sin pase guardado: pregunto si el backend pide ingreso (así la pantalla
  // de ingreso aparece de entrada y no después de varios errores).
  if (!leer()) {
    fetchOriginal(`${API()}/login.php`).then((r) => r.json()).then((d) => { if (d.requiere_login) mostrarIngreso(); }).catch(() => {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', botonSalir);
  else botonSalir();
})();
