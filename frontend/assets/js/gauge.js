/**
 * Zona horaria de TODAS las fechas que se muestran: UTC-3, la de la
 * plataforma de TTM (confirmado por Emiliano, 02/10/2026). Antes se usaba la
 * zona de la computadora que abría la página: si estaba configurada en otro
 * país, todas las horas salían corridas respecto de las de TTM.
 * Argentina no tiene horario de verano, así que UTC-3 es fijo.
 */
const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';
const DESFASE_AR_MS = 3 * 3600 * 1000;

/** Año, mes, día, hora y minuto de una fecha, vistos en hora argentina. */
function partesAR(fecha) {
  const d = new Date(new Date(fecha).getTime() - DESFASE_AR_MS);
  return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), h: d.getUTCHours(), mi: d.getUTCMinutes() };
}

/** Arma una fecha a partir de día y hora argentinos. */
function fechaAR(y, m, d, h = 0, mi = 0) {
  return new Date(Date.UTC(y, m - 1, d, h, mi) + DESFASE_AR_MS);
}

/**
 * Indicador KPI con barra horizontal (compacto).
 *
 * Primero lo hice de aguja, pero a Emiliano no le gustó: ocupaba mucho y
 * se leía peor. Ahora es una tarjeta chica: título, número grande, una
 * barra finita y una línea de detalle. Mantengo el nombre gauge() y los
 * mismos parámetros para no tener que tocar las pantallas que lo usan.
 *
 * Uso:
 *   contenedor.innerHTML = gauge({ titulo, icono, valor, sub, pct, invertido, enlace, datos });
 *   animarAgujas(contenedor);   // la barra crece desde cero
 *
 *   pct       -> de 0 a 1, cuánto se llena la barra
 *   invertido -> false (default): lleno = malo (tono rojizo)
 *                true: lleno = bueno (tono verdoso), ej. "cuánto revisaste"
 *   enlace    -> si la tarjeta lleva a otra pantalla
 *   datos     -> atributos data-* para que la pantalla le enganche un click
 */
function gauge({ titulo, icono = 'fa-circle', valor = '', sub = '', pct = 0, invertido = false, enlace = null, datos = null }) {
  const p = Math.max(0, Math.min(1, Number(pct) || 0));
  const tono = colorBarra(p, invertido);

  const atributos = datos ? Object.entries(datos).map(([k, v]) => ` data-${k}="${String(v).replace(/"/g, '&quot;')}"`).join('') : '';
  const clickeable = enlace || datos ? ' clickeable' : '';
  const etiqueta = enlace ? 'a' : 'div';
  const href = enlace ? ` href="${enlace}"` : '';

  return `
    <${etiqueta} class="kpi-card${clickeable}"${href}${atributos}>
      <div class="kpi-cab"><span class="kpi-titulo">${titulo}</span><i class="fa-solid ${icono}"></i></div>
      <div class="kpi-valor">${valor}</div>
      <div class="kpi-barra"><span data-ancho="${(p * 100).toFixed(1)}" style="width:0; background:${tono}"></span></div>
      <div class="kpi-sub">${sub}</div>
    </${etiqueta}>`;
}

/**
 * Color de la barra según qué tan llena está. Tonos apagados a propósito
 * (la versión anterior con verde/amarillo/rojo fuertes parecía una alarma).
 */
function colorBarra(p, invertido) {
  const bien = '#7fae8e', medio = '#d2ae63', mal = '#c98282';
  const nivel = invertido ? 1 - p : p;
  return nivel < 0.4 ? bien : nivel < 0.7 ? medio : mal;
}

/** Hace crecer cada barra desde cero hasta su valor (el nombre quedó de la versión de aguja). */
function animarAgujas(contenedor) {
  requestAnimationFrame(() => requestAnimationFrame(() => {
    contenedor.querySelectorAll('[data-ancho]').forEach(b => { b.style.width = b.dataset.ancho + '%'; });
  }));
}

/** Formato de números a la argentina: 1.234,5 */
function numeroAR(n, decimales = 0) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}
