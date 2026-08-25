/**
 * Badge fijo de "anomalías pendientes" en la barra superior — se carga en
 * las 3 pantallas (index.html, anomalias.html, informe.html). Muestra el
 * total GLOBAL de anomalías sin resolver (no depende de ningún filtro
 * puesto en pantalla). Baja solo cuando se resuelven anomalías —
 * ver marcarResueltasYRefrescarBadge() en informe.js.
 */
async function refrescarBadgePendientes() {
  const $badge = document.getElementById('badgeAnomalias');
  if (!$badge) return;

  try {
    const resp = await fetch(`${window.PRECISO_CONFIG.API_BASE}/pendientes.php`);
    const data = await resp.json();
    if (!resp.ok) return;

    if (data.pendientes > 0) {
      $badge.textContent = data.pendientes;
      $badge.style.display = 'inline-block';
    } else {
      $badge.style.display = 'none';
    }
  } catch (e) {
    // Silencioso — el badge simplemente no se actualiza, no rompe la página.
  }
}

refrescarBadgePendientes();
