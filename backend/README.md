# Backend — Preciso · Informes & Detección de Anomalías de Combustible

API en PHP puro que se conecta a la API existente de TTM (`ttm.com.ar/testing/api/get_full_data.php`),
guarda el historial, detecta anomalías de combustible (con filtro de falsos positivos de
pendiente, ver sección 3) y expone todo como JSON para que el frontend (carpeta `../frontend`)
lo consuma.

100% PHP puro, sin Composer. Solo necesita PHP 8+ con `curl` y `pdo_sqlite` habilitados
(viene por defecto en casi cualquier hosting compartido).

---

## 0. Lo primero que hay que hacer (si tocás la API de nuevo)

El mapeo de campos en `config/field_map.php` **ya está confirmado con datos reales** de
la API de prueba de Emiliano (equipo 1001, 23/07/2026). Si en algún momento la API cambia
de forma, o hay que sumar un equipo con un tipo de dato distinto, correr:

```bash
php scripts/test_api.php
php scripts/test_api.php 1001 "2026-07-20 00:00" "2026-07-23 23:59"
```

Esto pega contra la API real, imprime el JSON crudo y lista las claves del primer registro.

**⚠️ Ojo con esto, ya lo pisamos una vez:** la API separa las lecturas en eventos `DATA`
(combustible real, odómetro en 0) y `TIEMPO` (odómetro real, combustible en 0). Si algún
día aparece un tercer tipo de evento, `DataNormalizer.php` no lo va a enmascarar
automáticamente — revisar el bloque "máscara por evento" ahí mismo.

---

## 1. Estructura

```
config/
  config.php        → carga .env, define endpoint base y umbrales de anomalía
  field_map.php       → mapeo de campos del JSON real de la API (ya confirmado)
data/
  equipos_flota.json    → export de la flota (ver sección 2.5) para importar con scripts/importar_equipos.php
src/
  Env.php              → mini cargador de .env (sin librerías externas)
  ApiClient.php         → llama a get_full_data.php (cURL + reintentos)
  DataNormalizer.php     → traduce el JSON crudo a un formato interno estable
  Storage.php             → SQLite local: flota, lecturas, anomalías (ver src completo abajo)
  AnomalyDetector.php      → detección estadística con filtro de rebote de pendiente
  ReportBuilder.php         → arma los datos agregados para los informes
  SmtpMailer.php             → envío de alertas por email (SMTP directo, sin Composer)
database/
  schema.sql                → esquema SQLite (lecturas, anomalías, equipos, estado)
public/
  api/
    equipos.php              → GET → lista de la flota + anomalías últimos 30 días
    anomalias.php              → GET ?equipo=&tipo=&dias= → anomalías filtradas de toda la flota
    informe.php                 → GET ?equipo=&tipo=diario|semanal|mensual → informe completo
    _bootstrap_api.php           → CORS + helpers de respuesta JSON (no es un endpoint)
cron/
  check_alertas.php             → correr cada 5-10 min: procesa un LOTE de la flota, detecta y alerta
  generar_informes.php           → correr 1 vez al día: resumen de anomalías por mail (lee de la base local)
scripts/
  test_api.php                    → diagnóstico rápido de la API real (sección 0)
  importar_equipos.php             → alta/actualización masiva de la flota (sección 2.5)
  agregar_equipo.php                → alta de un equipo puntual (sección 2.5)
```

El frontend (HTML/CSS/JS estático, sin build) vive aparte en `../frontend/` y le pega a
estos endpoints por fetch. Ver el README de la raíz del repo para levantar los dos juntos.

---

## 2. Instalación

1. Copiá `.env.example` a `.env` y completá:
   - `API_BASE_URL` (ya viene con la URL de testing de Emiliano)
   - `FRONTEND_ORIGIN` — en local dejalo en `*`; en producción, poné el dominio real
     (ej. `https://preciso.tech`) para no dejar la API abierta a cualquiera.
   - Credenciales SMTP para las alertas
   - `ALERT_EMAILS` (destinatarios, separados por coma)
2. Verificá `php -m | grep -E "sqlite|curl"` (tienen que aparecer `curl`, `pdo_sqlite`, `sqlite3`).
3. Importá la flota real (ver sección 2.5 para el detalle):
   ```bash
   php scripts/importar_equipos.php
   ```
4. Levantá el servidor de la API:
   ```bash
   php -S localhost:8000 -t public
   ```
5. Probá que responde: `http://localhost:8000/api/equipos.php` tendría que devolver JSON.
6. Configurá los crons (ajustar rutas a tu hosting):
   ```
   */5 * * * * php /ruta/a/backend/cron/check_alertas.php >> /ruta/a/logs/alertas.log 2>&1
   0 6 * * *   php /ruta/a/backend/cron/generar_informes.php >> /ruta/a/logs/informes.log 2>&1
   ```

### Si no tenés PHP nativo (Docker)

```bash
cd backend
docker build -t preciso-backend .
docker run --rm -it -v "$(pwd)":/app -p 8000:8000 --env-file .env preciso-backend
```

(El Dockerfile ya trae un `CMD` que levanta el servidor solo, en el puerto que le pase la
variable `PORT` — 8000 por default en local. Este mismo Dockerfile es el que usa Railway
para desplegar, ver el `README.md` de la raíz del repo para esa guía.)

---

## 2.5 La flota (1000+ equipos)

Los equipos ya NO viven en un archivo de config — viven en la base (tabla `equipos`),
así que sumar una instalación nueva no requiere tocar código ni redeployar.

**Importar/actualizar la flota completa** (por ejemplo, si Emiliano manda un excel
nuevo — convertirlo a JSON con id/nombre y correr):
```bash
php scripts/importar_equipos.php data/equipos_flota.json
```
Es seguro correrlo de nuevo — actualiza los que ya existen, agrega los nuevos, no
borra nada.

**Sumar un solo equipo nuevo** (cuando Emiliano avise de una instalación puntual):
```bash
php scripts/agregar_equipo.php 1250 "AB123CD (Volvo - 380)"
```

⚠️ **Pendiente de confirmar con Emiliano**: si su sistema tiene un endpoint que
LISTE todos los equipos activos (no solo uno por vez), se podría automatizar esto
del todo — el cron detectaría equipos nuevos solo, sin que nadie tenga que avisar
ni correr un script. Sin ese endpoint, no hay forma de saber que existe un equipo
nuevo hasta que alguien lo diga.

**Cron con flota grande**: con 1000+ equipos, NO se le puede pegar a la API de
Emiliano esa cantidad de veces en cada corrida — ni por tiempo, ni por respeto a
su servidor (es de prueba). `check_alertas.php` procesa un lote por corrida
(`config.cron.batch_size`, default 100) y va rotando solo por toda la flota entre
corridas — con ese tamaño de lote y un cron cada 5 min, cubre unos 1250 equipos en
poco más de una hora. `generar_informes.php` no vuelve a pegarle a la API: lee de
la base, que ya está fresca gracias a `check_alertas.php`.

---

## 2.6 Pantalla de Anomalías (filtro por equipo y tipo)

`frontend/anomalias.html` — filtra anomalías de TODA la flota por equipo (buscador
tipo autocompletar, no un desplegable de 1241 opciones), por tipo de anomalía, y
por período. Lee del endpoint `GET /api/anomalias.php?equipo=&tipo=&dias=`, que a
su vez lee de la base local con una sola consulta agregada (rápido incluso con la
flota completa — probado en ~46ms con 1241 equipos).

Se puede llegar ya filtrada por un equipo puntual desde el panel (botón
"Anomalías" en cada fila) o por URL: `anomalias.html?equipo=1011`.

---

## 2.7 Exportar informe a PDF

Cada informe (`informe.html`) tiene un botón "Descargar PDF" que arma un PDF con:
resumen numérico, los gráficos (imagen directa de los canvas de Chart.js, calidad
exacta), un screenshot del mapa con el recorrido completo, y la tabla de
anomalías del período — todo del lado del navegador (jsPDF + html2canvas +
jspdf-autotable, vía CDN, sin nada nuevo que instalar en el backend).

Pensado como solución puntual mientras no estén los mails automáticos armados
(sección 3, "Generación automática de informes y alertas") — el día que eso ande,
el PDF puede seguir sirviendo para mandar algo manual cuando haga falta.

Nota técnica: el mapa usa tiles satelitales de Esri (World Imagery + capas de
referencia de calles y nombres de lugares, para vista híbrida) en vez de
OpenStreetMap directo — necesario para que `html2canvas` pueda capturar el mapa
sin problemas de CORS (con OSM directo, la captura del mapa queda en blanco).
Antes se usaba CARTO, pero empezó a pedir API key en septiembre/2026 y se
reemplazó — Esri no pide key nunca para este uso.

---

## 2.8 Base de datos: SQLite local, Postgres en producción

El disco de un contenedor (Railway u otro hosting) es **efímero** — se borra en
cada redeploy. Con 1000+ equipos generando historial todo el tiempo, perder todo
en cada `git push` no es viable. Por eso:

- **En tu máquina local**: sigue usando SQLite tal cual, sin instalar nada nuevo.
- **En Railway**: al crear un servicio de Postgres en el proyecto y linkear la
  variable `DATABASE_URL` en el backend (Variables → referenciar del servicio de
  Postgres), el sistema detecta que existe esa variable y usa Postgres
  automáticamente — no hay que cambiar ni una línea de código a mano.

`src/Storage.php` soporta los dos motores con el mismo código público (las
diferencias de sintaxis entre SQLite y Postgres — `INSERT OR IGNORE` vs
`ON CONFLICT DO NOTHING`, `PRAGMA table_info` vs `information_schema`, etc. —
quedan encapsuladas ahí adentro). `database/schema.sql` es para SQLite,
`database/schema_postgres.sql` es el mismo esquema traducido a Postgres — si se
agrega o cambia algo, hay que actualizar los dos.

Probado de punta a punta contra un Postgres real (no solo en teoría): conexión,
creación de esquema, import de la flota completa (1241 equipos, 41ms de
respuesta), guardado de lecturas con deduplicado, detección de anomalías, y el
filtro de flota con JOIN a la tabla de equipos.

---

## 3. Cómo funciona la detección de anomalías

Motor estadístico (no una caja negra ni un modelo entrenado). Por cada equipo, guarda un
historial de lecturas en SQLite y calcula, para cada intervalo entre dos lecturas:

**Primero, el filtro de "rebote de pendiente"** (esto es lo que soluciona el problema que
describió Emiliano: subidas/bajadas que hacen que el sensor lea de menos por un rato y
después se acomode solo). Ninguna caída se marca como anomalía en el momento en que ocurre:

- El sistema espera una ventana de confirmación (`recovery_window_minutes`, 20 min por
  defecto).
- Si el nivel **se recupera solo** dentro de esa ventana → se descarta, no es una anomalía
  real, y ni siquiera se usa para las estadísticas de ese vehículo.
- Si el nivel **no se recupera** → recién ahí se evalúa como candidata real.
- Si todavía no pasó suficiente tiempo real → queda pendiente y se reevalúa sola en la
  próxima corrida del cron. Por esto una alerta puede llegar 20-30 min después del evento,
  no al instante — es el precio de no confundir una pendiente con un robo.

Una vez confirmada (no se recuperó), se evalúa con:

- **Litros perdidos** y **litros perdidos por minuto** en el intervalo.
- Si el vehículo estaba **detenido**, cualquier caída confirmada se marca con más
  sensibilidad.
- Si estaba en movimiento, se compara contra la **media y desvío estándar histórico de
  ese mismo equipo** (z-score) — historial que ya viene limpio de rebotes de pendiente.
- Umbral absoluto de seguridad (`min_drop_liters_instant`) para cuando todavía no hay
  suficiente historial — todo en litros, no en % de un tanque (no existe esa capacidad).

Todos los umbrales están en `config/config.php`, comentados.

---

## 3.5 Modo simulador

`src/SimulatedApiClient.php` genera datos con la misma estructura exacta que la API real
(mismos nombres de campo, mismo comportamiento de eventos `DATA`/`TIEMPO`, mismo ruido
0/65535 en los tanques 2-4). Se activa con `API_SIMULATE=true` en `.env`.

La función `crearApiClient($config)` en `bootstrap.php` decide cuál usar — todo el resto
del sistema (`DataNormalizer`, `AnomalyDetector`, `ReportBuilder`) corre exactamente
igual sin importar cuál de los dos esté activo, porque ambos exponen el mismo método
`getFullData($equipo, $from, $to)` devolviendo la misma forma de JSON crudo.

Para volver a datos reales apenas la API de Emiliano ande: `API_SIMULATE=false` (o sacar
la variable directamente, el default es `false`). No hace falta tocar nada más.

---

## 4. Nota de diseño / estética

No tuve acceso al CSS real de `preciso.tech/landing/index.php` (sin browser en el entorno
donde armé esto). `../frontend/assets/css/style.css` usa una paleta razonable (navy + ámbar)
con variables CSS al principio del archivo — cambiar a los colores/logo reales de
preciso.tech es pegar los hex ahí, no reescribir CSS.

---

## 5. Subir esto a tu propio repositorio de GitHub

```bash
cd preciso-informes   # la carpeta raíz, la que tiene backend/ y frontend/ adentro
git remote add origin https://github.com/TU_USUARIO/preciso-informes.git
git branch -M main
git push -u origin main
```
