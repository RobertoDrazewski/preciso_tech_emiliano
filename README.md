# Preciso — Informes y Detección de Anomalías de Combustible

Sistema de monitoreo de flota que se integra con la plataforma de telemetría de
TTM Desarrollos para detectar automáticamente pérdidas de combustible,
consumo anómalo y agua en el combustible sobre una flota de más de 1.200
vehículos, con alertas automáticas y reportes exportables.

Desarrollado por **Puma Code** para **TTM Desarrollos / Preciso**.

---

## Tabla de contenidos

1. [Qué hace el sistema](#qué-hace-el-sistema)
2. [Stack tecnológico](#stack-tecnológico)
3. [Arquitectura](#arquitectura)
4. [Detección de anomalías](#detección-de-anomalías)
5. [Automatización (cron)](#automatización-cron)
6. [Estructura del proyecto](#estructura-del-proyecto)
7. [Instalación y desarrollo local](#instalación-y-desarrollo-local)
8. [Despliegue en producción](#despliegue-en-producción)
9. [Variables de entorno](#variables-de-entorno)
10. [Referencia de la API](#referencia-de-la-api)
11. [Estado actual y próximos pasos](#estado-actual-y-próximos-pasos)

---

## Qué hace el sistema

Preciso se conecta a las APIs de TTM (telemetría por vehículo y listado de
flota), guarda el historial de cada equipo, y analiza esos datos para
detectar automáticamente cinco tipos de anomalías de combustible — sin
intervención humana. Sobre eso construye:

- **Un panel web** con el estado de toda la flota, filtrable por cliente,
  nivel de servicio y presencia de anomalías.
- **Informes por vehículo** (diario / semanal / mensual) con gráficos de
  nivel de combustible, velocidad, detalle por tanque, y mapa satelital del
  recorrido — exportables a PDF.
- **Un proceso automático** que recorre toda la flota cada 5 minutos y
  genera alertas por email cuando detecta algo.
- **Un cruce directo** con el sistema de reportes de TTM: cada anomalía
  detectada tiene un link que abre el gráfico del sensor original en esa
  ventana de tiempo, para poder auditar la detección contra el dato crudo.

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3 puro — sin framework, sin Composer, sin dependencias externas |
| Base de datos | PostgreSQL en producción · SQLite en desarrollo local (mismo código, driver automático) |
| Frontend | HTML / CSS / JavaScript vanilla — sin build, sin framework |
| Gráficos | Chart.js |
| Mapas | Leaflet + tiles satelitales de Esri (World Imagery) |
| Exportación a PDF | jsPDF + jspdf-autotable + html2canvas (client-side, sin dependencias de servidor) |
| Infraestructura | Railway (contenedores Docker, Postgres administrado, cron jobs nativos) |
| Alertas | SMTP directo (sin proveedores de terceros) |
| Fuente de datos | API de telemetría y API de flota de TTM Desarrollos (`ttm.com.ar`) |

No hay build step en ningún lado — el backend es PHP servido directo, y el
frontend es HTML/JS estático. Esto es deliberado: minimiza superficie de
fallas de infraestructura y hace que cualquier desarrollador PHP pueda
mantener el proyecto sin herramientas adicionales.

---

## Arquitectura

```
┌─────────────┐      ┌──────────────────┐      ┌─────────────────┐
│  Frontend    │ ───► │  Backend (API)    │ ───► │  APIs de TTM     │
│  (estático)  │      │  PHP              │      │  (telemetría +   │
└─────────────┘      └────────┬─────────┘      │   flota)          │
                               │                └─────────────────┘
                               ▼
                      ┌──────────────────┐
                      │  PostgreSQL       │
                      │  (lecturas,       │
                      │   anomalías,      │
                      │   flota)          │
                      └──────────────────┘
                               ▲
                               │
                      ┌──────────────────┐
                      │  Cron (cada 5')   │
                      │  detecta y alerta │
                      └──────────────────┘
```

El backend expone una API REST propia (JSON) que el frontend consume por
`fetch`. El backend, a su vez, es el único que habla con las APIs externas
de TTM — el frontend nunca les pega directo.

Dos fuentes de datos externas, con roles distintos:

- **API de telemetría** (`get_full_data.php`) — por vehículo, con rango de
  fechas. Se consulta seguido (cada 5 min, rotando por la flota).
- **API de flota** (`get_devices.php`) — todo el listado de equipos de una
  sola consulta (cliente, estado, capacidad de tanque, diagnóstico del
  dispositivo, nivel de servicio). Se sincroniza manualmente o de forma
  esporádica, no hace falta más seguido — la flota no cambia tan rápido.

---

## Detección de anomalías

### El enfoque: un motor estadístico explicable, no una caja negra

El sistema **no usa un modelo de machine learning entrenado**. Usa un motor
de reglas y estadística aplicada sobre el historial propio de cada
vehículo — cada anomalía que reporta viene acompañada de la cuenta exacta
que la generó, auditable línea por línea. Esta decisión de diseño es
intencional: un modelo entrenado (tipo Isolation Forest) recién tiene
sentido con semanas de historial real acumulado sobre la flota completa, y
hasta entonces un sistema no supervisado corre el riesgo de aprender sobre
datos con ruido o incompletos. El motor actual funciona desde el primer
día, sin período de entrenamiento, y es el que efectivamente alimenta de
historial limpio al futuro modelo entrenado (ver [Roadmap](#estado-actual-y-próximos-pasos)).

### El filtro base: descartar el "rebote de pendiente"

Antes de evaluar cualquier caída de combustible como anomalía, el sistema
espera una ventana de confirmación (20 minutos por defecto). Si el nivel se
recupera solo dentro de esa ventana — típico cuando el vehículo sube o baja
una pendiente pronunciada y el sensor lee de menos por un momento — la
caída se descarta como ruido de sensor, no como anomalía, y ni siquiera se
usa para las estadísticas del vehículo. Solo una caída que **no se
recupera** (al menos el 70% de lo caído) pasa a evaluación real.

Esto significa que una alerta puede llegar 20-30 minutos después del evento
real — es el costo de no confundir una pendiente con una fuga.

### Techo de sensatez (control de calidad de dato)

Todas las lecturas de combustible pasan primero por un control de rango
físico: si el dispositivo reporta un valor imposible (por ejemplo, un
salto a decenas de miles de litros durante una pérdida y reconexión de
alimentación del equipo — un evento real y conocido en esta flota), esa
lectura se descarta en el origen, antes de llegar a cualquier gráfico,
cálculo de consumo o detector. Se usa la capacidad real del tanque cuando
se conoce (vía la API de flota); si no, un techo genérico conservador.

### Los cinco tipos de anomalía

| Tipo | Cómo se detecta |
|---|---|
| **Descarga con vehículo detenido** | Caída confirmada de combustible mientras el vehículo está parado, por encima de 4 litros — un vehículo detenido no debería perder combustible. |
| **Caída instantánea** | Caída confirmada de al menos 15 litros en 10 minutos o menos, sin necesitar historial previo — regla de seguridad válida desde el primer día. |
| **Consumo anómalo** | Una vez que hay al menos 20 caídas confirmadas en el historial del vehículo, cualquier caída nueva se compara estadísticamente (z-score ≥ 3 desvíos estándar) contra el comportamiento normal de **ese vehículo puntual** — no un promedio genérico de la flota. |
| **Agua en combustible** | Dos señales: el campo del sensor de telemetría (booleano), y el diagnóstico `AGUA` que expone la API de flota de TTM. Se marca en el momento en que la señal pasa a estar activa, no en cada lectura mientras se mantiene. |
| **Ralentí** | Motor encendido + vehículo detenido + consumo real sostenido durante al menos 15 minutos y 1 litro. A propósito no depende de GPS — muchos equipos operan bajo techo, donde la señal de posición no es confiable. |

Todos los umbrales están centralizados en `backend/config/config.php`,
documentados y ajustables sin tocar lógica de negocio.

### Corroboración adicional

Cuando el dispositivo reporta el campo `variacion` (carga o descarga
informada por su propio sensor) en el mismo momento que se confirma una
anomalía, el sistema lo menciona explícitamente en el detalle como
corroboración — no como criterio único de detección.

### Cruce con el sistema de origen

Cada anomalía reportada incluye un link directo al sistema de reportes de
TTM, filtrado por ese vehículo y una ventana de ±1 día alrededor del
evento — para poder comparar visualmente la detección contra el gráfico
del sensor crudo sin armar la consulta a mano.

---

## Automatización (cron)

Un proceso (`cron/check_alertas.php`) recorre la flota completa de forma
continua y sin intervención humana:

- Corre **cada 5 minutos**.
- Procesa un **lote de 100 equipos** por corrida (configurable), rotando
  por toda la flota entre corridas — con más de 1.200 equipos, consultar
  la API completa en cada corrida no es viable ni razonable para el
  servidor de origen. Con este ritmo, la flota completa se cubre cada
  poco más de una hora.
- Cada consulta pide solo los **últimos 90 minutos** de cada vehículo —
  nunca historial extenso de forma automática.
- Detecta anomalías nuevas, las guarda, y dispara alertas por email
  (con deduplicado: una misma anomalía nunca genera dos alertas).

Un segundo proceso (`cron/generar_informes.php`), pensado para correr una
vez al día, arma un resumen de las anomalías detectadas — lee de la base
local, no vuelve a consultar la API externa.

---

## Estructura del proyecto

```
backend/
  bootstrap.php              Carga config y clases, define el punto de entrada común
  config/
    config.php                Configuración central: umbrales, credenciales, endpoints
    field_map.php              Mapeo de campos del JSON de telemetría real
  src/
    ApiClient.php               Cliente de la API de telemetría (por vehículo)
    SimulatedApiClient.php       Generador de datos de prueba con la misma forma que la API real
    DevicesApiClient.php         Cliente de la API de flota (get_devices.php)
    DataNormalizer.php           Normaliza telemetría cruda a un formato interno estable
    DevicesNormalizer.php        Normaliza el listado de flota
    Storage.php                  Capa de datos (SQLite / PostgreSQL, mismo código)
    AnomalyDetector.php          Motor de detección — los 5 tipos de anomalía
    ReportBuilder.php            Arma los datos agregados de cada informe
    SmtpMailer.php                Envío de alertas por email
  database/
    schema.sql                  Esquema SQLite
    schema_postgres.sql          Mismo esquema, sintaxis PostgreSQL
  public/api/                   Endpoints REST (ver referencia más abajo)
  cron/                         Procesos automáticos (detección + resumen diario)
  scripts/                      Utilidades de línea de comandos (sincronización, diagnóstico, limpieza)

frontend/
  index.html                    Panel principal — flota, filtros, búsqueda
  anomalias.html                 Anomalías de toda la flota, filtrables
  informe.html                    Informe por vehículo (diario/semanal/mensual) + exportar a PDF
  assets/js/                     Lógica de cada pantalla (vanilla JS, sin build)
  assets/css/                    Estilos
```

---

## Instalación y desarrollo local

Requisitos: PHP 8+ con las extensiones `curl` y `pdo_sqlite`.

```bash
# Backend
cd backend
cp .env.example .env
# completar API_BASE_URL, credenciales SMTP, etc. — ver sección de variables de entorno
php -S localhost:8000 -t public

# Frontend (en otra terminal)
cd frontend
php -S localhost:3000
```

Abrir `http://localhost:3000`. Sin conexión a la API real, activar
`API_SIMULATE="true"` en el `.env` del backend — genera datos con la misma
estructura exacta que la API real (mismos campos, mismo comportamiento),
así que todo el sistema (panel, gráficos, detección) corre igual que con
datos reales, sin tocar código.

Para cargar la flota localmente:

```bash
php scripts/sincronizar_equipos.php   # trae el listado real desde la API de flota
```

### Con Docker (alternativa sin PHP nativo)

```bash
cd backend
docker build -t preciso-backend .
docker run --rm -it -v "$(pwd)":/app -p 8000:8000 --env-file .env preciso-backend
```

---

## Despliegue en producción

El proyecto corre en **Railway**, con cuatro servicios dentro del mismo
proyecto:

1. **Backend** — Root Directory `backend/`, detecta el `Dockerfile`
   automáticamente.
2. **Frontend** — mismo repositorio, Root Directory `frontend/`.
3. **PostgreSQL** — servicio de base de datos administrado por Railway,
   vinculado al backend vía la variable `DATABASE_URL` (referenciada, sin
   copiar credenciales a mano).
4. **Cron de detección** — un cuarto servicio, mismo repositorio y Root
   Directory que el backend, pero con **Start Command** sobreescrito a
   `php cron/check_alertas.php` y **Cron Schedule** en `*/5 * * * *`.

El disco de un contenedor es efímero — por eso la base de datos vive en un
servicio de PostgreSQL separado, no en el mismo contenedor del backend.

---

## Variables de entorno

| Variable | Descripción |
|---|---|
| `API_BASE_URL` | Endpoint de la API de telemetría de TTM |
| `API_TOKEN` | Token de autenticación, si la API lo requiere |
| `API_SIMULATE` | `"true"` para usar el generador de datos de prueba en vez de la API real |
| `API_INSECURE_SSL_TESTING` | Solo diagnóstico local — nunca en producción |
| `DEVICES_API_URL` | Endpoint de la API de flota de TTM |
| `DATABASE_URL` | Cadena de conexión a PostgreSQL — si está presente, se usa Postgres; si no, SQLite |
| `DB_PATH` | Ruta del archivo SQLite (solo desarrollo local) |
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_FROM` / `SMTP_FROM_NAME` | Credenciales de envío de alertas |
| `ALERT_EMAILS` | Destinatarios de las alertas, separados por coma |
| `FRONTEND_ORIGIN` | Origen permitido para CORS — el dominio del frontend en producción |
| `APP_TIMEZONE` | Zona horaria de la aplicación |

Ver `backend/.env.example` para la plantilla completa.

---

## Referencia de la API

Todos los endpoints están en `backend/public/api/` y devuelven JSON.

| Endpoint | Método | Descripción |
|---|---|---|
| `/api/equipos.php` | GET | Listado de la flota, con anomalías de los últimos 30 días por equipo |
| `/api/informe.php?equipo=&tipo=` | GET | Informe completo de un vehículo (`tipo`: diario / semanal / mensual) |
| `/api/anomalias.php?equipo=&tipo=&dias=` | GET | Anomalías de toda la flota, filtrables por equipo, tipo y período |
| `/api/marcar_resueltas.php` | POST | Marca anomalías como atendidas |
| `/api/pendientes.php` | GET | Cantidad de anomalías sin resolver |

---

## Estado actual y próximos pasos

**En producción, funcionando:**
- Conexión a las dos APIs reales de TTM (telemetría y flota).
- Detección automática de los 5 tipos de anomalía sobre la flota completa.
- Proceso automático (cron) corriendo cada 5 minutos con alertas por email.
- Panel con filtros por cliente, servicio y presencia de anomalías.
- Exportación de informes a PDF.
- Base de datos PostgreSQL persistente.

**Pendiente:**
- Confirmar con TTM el nombre exacto del campo de agua en combustible en el
  JSON de telemetría (hoy se valida por alias candidatos; la señal de la
  API de flota ya está confirmada y en uso).
- Autenticación en la API propia (hoy abierta al `FRONTEND_ORIGIN` configurado).
- Paleta de colores / identidad visual definitiva.
- Alertas por WhatsApp, si se define como canal adicional.
- **Modelo de machine learning entrenado**: una vez acumuladas varias
  semanas de historial real de la flota completa (el cron ya está
  alimentando esa base desde que está en producción), evaluar sumar un
  modelo no supervisado (tipo Isolation Forest) como capa adicional sobre
  el motor estadístico actual — no como reemplazo. El objetivo sería
  capturar patrones más sutiles que combinan varias señales a la vez, algo
  que las reglas fijas no cubren por diseño.

---

Puma Code — desarrollo y mantenimiento.
