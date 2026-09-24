/**
 * Indicador de aguja (tipo velocímetro) para todos los KPIs.
 *
 * Uso:
 *   contenedor.innerHTML = gauge({ titulo, icono, color, valor, sub, pct, invertido });
 *   animarAgujas(contenedor);   // la aguja sale de cero y va a su lugar
 *
 *   pct       -> de 0 a 1, dónde apunta la aguja
 *   invertido -> false (default): alto = malo (rojo a la derecha).
 *                true: alto = bueno (verde a la derecha), por ejemplo
 *                "cuánto revisaste".
 *
 * El arco tiene tres zonas (40% / 30% / 30%). Todo es SVG a mano, sin
 * librerías, así pesa nada y se ve nítido en cualquier pantalla.
 */
function gauge({ titulo, icono = 'fa-circle', color = '#999', valor = '', sub = '', pct = 0, invertido = false, enlace = null, datos = null }) {
  const p = Math.max(0, Math.min(1, Number(pct) || 0));
  // Tonos suaves a propósito: con verde/amarillo/rojo saturados toda la
  // pantalla parecía una alarma. El color queda como referencia, no grita.
  const bien = '#cfe3d6', medio = '#efe2c4', mal = '#ecc9c9';
  const zonas = invertido
    ? [[mal, 0, 0.4], [medio, 0.4, 0.7], [bien, 0.7, 1]]
    : [[bien, 0, 0.4], [medio, 0.4, 0.7], [mal, 0.7, 1]];

  // Punto del arco para una fracción (0 = izquierda, 1 = derecha).
  const punto = (f, r) => {
    const ang = Math.PI * (1 - f);
    return [100 + r * Math.cos(ang), 100 - r * Math.sin(ang)];
  };
  const arco = (f1, f2, r) => {
    const [x1, y1] = punto(f1, r);
    const [x2, y2] = punto(f2, r);
    return `M ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 0 1 ${x2.toFixed(1)} ${y2.toFixed(1)}`;
  };

  const arcos = zonas.map(([c, a, b]) =>
    `<path d="${arco(a, b, 80)}" stroke="${c}" stroke-width="12" fill="none"/>`).join('');
  const marcas = [0, 0.25, 0.5, 0.75, 1].map(f => {
    const [x1, y1] = punto(f, 62);
    const [x2, y2] = punto(f, 68);
    return `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="#ced4da" stroke-width="1.5"/>`;
  }).join('');

  // La aguja se dibuja apuntando a la izquierda (0) y se gira según pct.
  const grados = (180 * p).toFixed(1);
  // Si la tarjeta lleva a algún lado (enlace) o la pantalla le engancha un
  // click (datos -> atributos data-*), queda "clickeable" con su cursor.
  const atributos = datos ? Object.entries(datos).map(([k, v]) => ` data-${k}="${String(v).replace(/"/g, '&quot;')}"`).join('') : '';
  const clickeable = enlace || datos ? ' clickeable' : '';
  const etiqueta = enlace ? 'a' : 'div';
  const href = enlace ? ` href="${enlace}"` : '';
  return `
    <${etiqueta} class="gauge-card${clickeable}"${href}${atributos} style="--g-color:${color}">
      <div class="g-titulo">${titulo}</div>
      <i class="fa-solid ${icono} g-icono"></i>
      <svg viewBox="0 0 200 112" aria-hidden="true">
        ${arcos}${marcas}
        <g class="g-aguja" data-grados="${grados}" style="transform: rotate(0deg)">
          <polygon points="100,96.5 100,103.5 28,100" fill="#495057"/>
        </g>
        <circle cx="100" cy="100" r="7" fill="#495057"/>
        <circle cx="100" cy="100" r="2.5" fill="#fff"/>
      </svg>
      <div class="g-valor">${valor}</div>
      <div class="g-sub">${sub}</div>
    </${etiqueta}>`;
}

/** Lleva cada aguja a su lugar con una animación corta. */
function animarAgujas(contenedor) {
  requestAnimationFrame(() => requestAnimationFrame(() => {
    contenedor.querySelectorAll('.g-aguja').forEach(g => {
      g.style.transform = `rotate(${g.dataset.grados}deg)`;
    });
  }));
}

/** Formato de números a la argentina: 1.234,5 */
function numeroAR(n, decimales = 0) {
  return Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}
