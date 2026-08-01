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
  equipos.php        → tu flota: id de equipo + nombre
  field_map.php       → mapeo de campos del JSON real de la API (ya confirmado)
src/
  Env.php              → mini cargador de .env (sin librerías externas)
  ApiClient.php         → llama a get_full_data.php (cURL + reintentos)
  DataNormalizer.php     → traduce el JSON crudo a un formato interno estable
  Storage.php             → SQLite local: guarda lecturas y anomalías ya alertadas
  AnomalyDetector.php      → detección estadística con filtro de rebote de pendiente
  ReportBuilder.php         → arma los datos agregados para los informes
  SmtpMailer.php             → envío de alertas por email (SMTP directo, sin Composer)
database/
  schema.sql                → esquema SQLite
public/
  api/
    equipos.php              → GET → lista de la flota + anomalías últimos 30 días
    informe.php               → GET ?equipo=&tipo=diario|semanal|mensual → informe completo
    _bootstrap_api.php         → CORS + helpers de respuesta JSON (no es un endpoint)
cron/
  check_alertas.php             → correr cada 5-10 min: detecta anomalías nuevas y alerta
  generar_informes.php           → correr 1 vez al día: informe diario + resumen por mail
scripts/
  test_api.php                    → diagnóstico rápido de la API real (sección 0)
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
3. Cargá tu flota real en `config/equipos.php` (solo id + nombre — ya no hace falta
   capacidad de tanque, todo se calcula en litros absolutos).
4. Levantá el servidor de la API:
   ```bash
   php -S localhost:8000 -t public
   ```
5. Probá que responde: `http://localhost:8000/api/equipos.php` tendría que devolver JSON.
6. Configurá los crons (ajustar rutas a tu hosting):
   ```
   */10 * * * * php /ruta/a/backend/cron/check_alertas.php >> /ruta/a/logs/alertas.log 2>&1
   0 6 * * *    php /ruta/a/backend/cron/generar_informes.php >> /ruta/a/logs/informes.log 2>&1
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
