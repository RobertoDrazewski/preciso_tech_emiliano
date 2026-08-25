# Preciso · Informes & Anomalías de Combustible

Che Emiliano, buenas — segunda vuelta, con todo lo que me pasaste por audio ya metido
adentro. Nada de porcentaje de tanque (ya lo saqué de raíz, todo litros posta como me
dijiste), sumé los tanques 1 a 4 con la regla de descarte que me diste, y dejé todo
armado para subirlo a un hosting de prueba en vez de que lo veas solo en mi máquina.
Vamos de nuevo.

---

## 🍷 Qué es esto, en criollo

Un sistema que le pega a tu API, junta los datos de posición/velocidad/combustible/
kilometraje, arma informes diario/semanal/mensual con gráficos y mapa, y detecta cuándo
hay una caída de combustible **real** (fuga, robo, verdura) distinguiéndola de una caída
**de mentira** (pendiente que hace que el sensor lea de menos por un rato y después se
acomoda solo).

Separado en dos partes:

- **`backend/`** → el cerebro. PHP puro, sin build, sin Composer. Le pega a tu API,
  guarda todo en SQLite, corre la detección de anomalías, y expone todo como JSON.
- **`frontend/`** → la cara. HTML/CSS/JS simple que le pega al backend y dibuja el
  panel, los gráficos y el mapa.

---

## ▶️ Verlo andar en tu máquina (dos minutos)

⚠️ **La API de prueba que me pasaste dejó de responder** (`{"success":false,"error":"Faltan
parámetros obligatorios..."}`, incluso probando tu URL de ejemplo tal cual con curl, sin
pasar por mi código para nada). Mientras tanto, activé un **modo simulador**: genera datos
con la estructura exacta de tu API real (mismos campos, mismo comportamiento DATA/TIEMPO,
mismo ruido de 0/65535 en los tanques), así que todo lo que ves funcionando — el panel,
los gráficos, la detección de anomalías — es exactamente lo mismo que va a correr el día
que tu API esté de vuelta. Cuando me confirmes que anda, cambio una sola variable y
listo, no toco nada de código.

```bash
# Terminal 1
cd backend
cp .env.example .env
# abrí .env y poné API_SIMULATE="true" (así viene el ejemplo del token, activalo vos)
php -S localhost:8000 -t public

# Terminal 2
cd frontend
php -S localhost:3000
```

Abrís `http://localhost:3000`. Vas a ver un cartel amarillo arriba de todo avisando que
son datos simulados — apenas tu API ande de nuevo, pongo `API_SIMULATE="false"` en el
`.env` y desaparece solo, sin tocar una línea de código.

---

## ☁️ Verlo andar en un link, sin instalar nada (Railway)

Para que lo veas vos (o cualquiera del equipo) sin depender de mi máquina ni de la tuya,
armé los dos servicios listos para desplegar en Railway tal cual están, con Docker.

1. Este repo ya está en GitHub. En Railway: **New Project → Deploy from GitHub repo** →
   elegís `preciso_tech_emiliano`.
2. Te crea un servicio. Andá a **Settings → Root Directory** y poné `backend`. Railway
   detecta el `Dockerfile` solo y lo despliega.
3. En ese mismo servicio, pestaña **Variables**, cargá las mismas del `.env.example` de
   `backend/` (`API_BASE_URL`, `SMTP_*`, `ALERT_EMAILS`, etc). Mientras el SSL de
   `ttm.com.ar` no esté arreglado (ver más abajo), sumá también:
   ```
   API_INSECURE_SSL_TESTING=true
   ```
4. **Settings → Networking → Generate Domain** para que te dé una URL pública tipo
   `preciso-backend-production.up.railway.app`. Copiá esa URL.
5. Agregá un **segundo servicio** en el mismo proyecto: de nuevo desde el mismo repo de
   GitHub, pero con **Root Directory** = `frontend`.
6. En ese servicio, Variables, cargá:
   ```
   API_BASE=https://LA-URL-DEL-BACKEND-DE-ARRIBA/api
   ```
7. Generate Domain también para el frontend.
8. Volvé al servicio del **backend** y en Variables agregá:
   ```
   FRONTEND_ORIGIN=https://preciso-tech.up.railway.app
   ```
   (esto es para que el navegador no bloquee los pedidos del frontend al backend — sin
   esto, CORS te va a tirar error en la consola).
9. Redeploy de los dos servicios y listo, ya tenés un link para mandar.

**Un detalle a tener en cuenta:** la base SQLite vive en el disco del contenedor del
backend, y Railway borra ese disco en cada redeploy (a menos que le agregues un
**Volume** — Settings → Volumes → montar en `/app/storage`). Para una demo no hace
falta, para dejarlo en producción de una sí conviene sumarlo.

---

## 🔥 Ralentí — 5to tipo de anomalía (sumado hoy)

Pedido de Emiliano: detectar cuándo un camión queda con el **motor encendido,
detenido, y consumiendo combustible** sin necesidad — a propósito **sin usar
GPS/posición**, porque muchos equipos quedan bajo techo y ahí la señal de GPS
no sirve.

Cómo funciona:
- Se arma un bloque cada vez que el equipo tiene `motor_encendido=true` y
  `velocidad=0` de forma sostenida.
- Solo cuenta si el bloque dura al menos 15 minutos **y** consumió al menos
  1 litro en ese tramo — así no marca cada semáforo en rojo como ralentí.
- Un bloque que sigue en curso (el vehículo seguía detenido en la última
  lectura de la ventana consultada) **no se reporta todavía** — se espera a
  la próxima corrida para ver cómo termina. Esto evita que el mismo ralentí
  largo genere varias entradas distintas con límites que no coinciden entre
  corridas del cron (mismo tipo de problema que ya resolvimos con el bug de
  los mails duplicados del simulador — acá lo evitamos desde el diseño).
- Probado con 3 casos: parada corta (no marca), ralentí largo sin GPS
  (marca igual, sin depender de la posición), y bloque sin cerrar al final
  de los datos (no marca todavía).

## 🔌 Plan a futuro: API de equipos de Emiliano

Con el pago al día, Emiliano va a entregar una API nueva que devuelve el
listado completo de equipos (activo, cliente, etc. — ver sección de
confirmaciones más abajo). Cuando esté disponible:

- Va a reemplazar el `scripts/importar_equipos.php` manual (excel → JSON →
  import) por una sincronización automática contra esa API.
- La tabla `equipos` de nuestra base sigue existiendo igual — pasa a ser un
  espejo/caché que se actualiza solo, en vez de algo que hay que cargar a
  mano cada vez que instalan un equipo nuevo.
- Falta que Emiliano pase la estructura exacta del JSON y el método de
  autenticación para armar el sync — anotado como pendiente.

---

## 🛠️ Qué cambié con lo que me dijiste por audio

- **Nada de % de tanque.** Lo saqué de raíz — la flota ya no pide capacidad
  de tanque, y todos los umbrales de anomalía en `config/config.php` están en litros
  directos.
- **`combustible_total` ("nivel total") es el campo que uso para todos los cálculos**,
  tal cual me dijiste.
- **Sumé tanque1 a tanque4** (tus campos `nivel1`-`nivel4`), con tu regla exacta: si
  viene en `0` (recién desconectado) o en `65535`/`0xFFFF` (tanque no presente), se
  ignora esa lectura. Lo probé a mano con un caso mezclando valores válidos y basura, y
  filtra bien. El informe ahora muestra un gráfico aparte con el detalle por tanque
  cuando el vehículo tiene más de uno.
- **Sumé el campo `variacion`.** Lo guardo y lo muestro como dato de corroboración en el
  detalle de la anomalía (si el dispositivo marcó una variación distinta de cero
  justo cuando se confirma una caída real, lo digo en el mensaje). Todavía no lo usé
  como filtro principal — no vi un caso real con valor distinto de cero para confirmar
  bien el signo (positivo = carga, negativo = descarga es mi hipótesis). En cuanto
  tengas un caso real de robo o carga posta, pasámelo y calibro esto mejor.
- Sigue en pie el filtro de "rebote de pendiente": ninguna caída se marca como anomalía
  hasta que pasan unos minutos y se confirma que no se recuperó sola.
- **Encontré y arreglé un bug del detector**: si justo entre dos lecturas con
  combustible caía un registro `TIEMPO` (que trae el combustible en null), la
  comparación se saltaba entera y una caída real podía quedar invisible. Ahora compara
  las lecturas de combustible válidas consecutivas, salteando los `TIEMPO` de en medio
  sin perderse nada.
- Ahora se puede ver andando en un link (Railway), no solo en mi máquina.
- Armé un **modo simulador** (ver más arriba) para poder mostrarte todo funcionando
  mientras tu API de prueba está caída — mismo formato de datos exacto, se apaga con
  una sola variable apenas la reactives.

---

## 🚛 Toda la flota — lo que se sumó hoy (1241 equipos)

Con la lista completa que pasó Emiliano, esto ya no es una prueba con un equipo — es
la flota entera conectada. Separo bien qué es infraestructura real (lista para
mostrar hoy) y qué es el modelo de ML (todavía no, y te explico por qué):

**✅ Infraestructura para toda la flota — hecho y probado:**
- Los 1241 equipos ya están cargados en el sistema (vía `scripts/importar_equipos.php`),
  y sumar uno nuevo de acá en más es un comando de un segundo, sin redeployar
  (`scripts/agregar_equipo.php`) — no hay que tocar código cada vez que Emiliano
  instale un equipo nuevo.
- El cron que revisa anomalías **rota por toda la flota en lotes**, no le pega a la
  API de Emiliano 1241 veces de una — eso le podría haber tumbado el servidor de
  prueba. Con el tamaño de lote actual, cubre toda la flota cada poco más de una hora.
- Pantalla nueva de **Anomalías** con buscador por patente/ID y filtro por tipo —
  funciona sobre toda la flota, no equipo por equipo.
- Probé el endpoint que lista los 1241 equipos con sus anomalías: **46 milisegundos**.
  A esta escala, cualquier loop ingenuo hubiera sido carísimo — lo armé con consultas
  agregadas de una sola vez.
- Nuevo botón de **descargar PDF** en cada informe: junta los gráficos, un screenshot
  real del mapa con el recorrido, y la tabla de anomalías del período — para poder
  mandarte algo mientras no tenemos los mails automáticos armados del todo.

**⚠️ El modelo de machine learning ENTRENADO — todavía no, y esto es importante:**
Lo que ya funciona (el motor estadístico que detecta anomalías, explicado en el
documento anterior) ya corre sobre toda la flota, no sobre un equipo. Pero un
modelo *entrenado* de verdad necesita que el sistema esté **acumulando datos reales
de los 1241 vehículos durante un tiempo** — no hay atajo para eso, ni siquiera
teniendo la lista completa hoy. Lo que sí arranca a partir de ahora: cada corrida del
cron guarda historial real de la flota completa, así que el reloj para poder entrenar
algo en serio empieza a correr desde hoy.

---

## 📋 Preguntas para Emiliano — estado

**RESUELTO (14/08/2026)**: ¿Endpoint que liste todos los equipos activos? — Sí, Emiliano
confirmó que va a haber una "API 2" con el listado completo (activo, cliente, etc.).
Falta que defina la estructura del JSON exacta y el método de autenticación.

---

0. **🚨 Tu API de prueba dejó de responder.** Probé la URL exacta que me pasaste
   (`get_full_data.php?date_range=...&equipo=1001`) con curl puro, sin pasar por mi
   código, y me devuelve:
   ```json
   {"success":false,"error":"Faltan parámetros obligatorios: equipo, date_range."}
   ```
   con los mismos parámetros que antes funcionaban. ¿Cambiaste algo del lado de la API
   (nombres de parámetros, formato de `date_range`, algo)? Mientras tanto estoy
   mostrando todo con el simulador (ver arriba), pero para conectar con datos reales de
   nuevo necesito que esto vuelva a andar o que me pases el formato nuevo.
1. **Lo del certificado SSL de `ttm.com.ar`.** Como me dijiste que el hosting es medio
   automático y les indicás qué hacer, achicá esto y pasaselo tal cual:

   > *"El certificado SSL de ttm.com.ar está incompleto: el servidor solo manda el
   > certificado del dominio (`ttm.com.ar`), pero no la cadena intermedia de Let's
   > Encrypt. Los navegadores lo disimulan buscando el intermedio por su cuenta, pero
   > cualquier sistema automático (APIs, apps, integraciones) no puede validar la
   > conexión así — el error que da es 'unable to get local issuer certificate'. Hay
   > que volver a emitir/instalar el certificado sirviendo el 'fullchain' completo
   > (certificado + intermedio), no solo el certificado del dominio. Si el hosting
   > tiene una opción tipo 'AutoSSL' o reinstalar el certificado Let's Encrypt, con
   > volver a correrla debería alcanzar."*

2. **La lista completa de equipos/patentes** que vas a querer monitorear, con sus IDs
   tal como los espera tu API (ya no hace falta capacidad de tanque, ese pedido quedó
   viejo). Por ahora sigue siendo manual (excel → `scripts/importar_equipos.php`) hasta
   que la "API 2" que confirmaste esté lista — ver sección de arriba.
3. **Paleta de colores / logo real de preciso.tech** — no tengo forma de sacarlos del
   sitio automáticamente. Pasame los hex o el sitio y lo dejo con tu marca.
4. **Email(s) que van a recibir las alertas**, y si además de mail les interesa WhatsApp.
5. **¿La API en producción va a tener algún tipo de token?** Hoy está abierta.
6. **Feedback sobre el tiempo de confirmación** (hoy 20 min) una vez que lo veas andar
   con datos reales de unos días.
7. **Un caso real de robo/fuga o de carga de combustible**, si tenés alguno en los
   registros históricos — me sirve muchísimo para calibrar bien el campo `variacion` y
   los umbrales en litros con datos posta en vez de estimaciones mías.

---

## 🔔 Sistema de alarma automática + PDF que resuelve (hoy)

Esto es lo que pediste hoy: una alarma fija en el panel que sube sola cuando el
sistema detecta algo, y baja sola cuando alguien la atiende.

- **Badge rojo fijo** en "Anomalías" de la barra superior, visible en las 3
  pantallas (Panel, Anomalías, Informe) — muestra el total de anomalías
  PENDIENTES de toda la flota, no depende de ningún filtro puesto en pantalla.
- **Sube solo**: cada vez que el cron (o alguien mirando un informe) detecta una
  anomalía nueva, el contador sube.
- **Baja cuando se resuelve**: al descargar el PDF de un informe, el sistema
  marca automáticamente como "resueltas" todas las anomalías de ese equipo en
  ese período — ahí el contador baja.
- **Probado de punta a punta con navegador real** (no solo con curl): generé
  una anomalía nueva, confirmé que el badge aparece con "1" en el panel,
  resolví esa anomalía, confirmé que el badge desaparece. Los tres pasos
  filmados con captura de pantalla real, no supuestos.
- **4 KPI fijos** en la pantalla de Anomalías (Descarga Detenido, Caída
  Instantánea, Consumo Anómalo, Agua en Combustible) — siempre se muestran los
  4, con "0" cuando no hay, en vez de aparecer/desaparecer tarjetas según haya
  datos o no.

## 💧 Agua en combustible (WIF) — sumado hoy

Confirmado por Emiliano (14/08/2026): el sensor WIF (Water In Fuel) es
booleano, `0` o `1`. Se agregó como:
- Campo nuevo en `field_map.php` / `DataNormalizer.php` (con varios alias
  candidatos del nombre real del campo — todavía no confirmado con datos
  reales, correr `scripts/test_api.php` para ajustar si hace falta).
- **4to tipo de anomalía**: `agua_combustible`. A diferencia de las otras 3, no
  mide litros ni necesita ventana de confirmación — es una lectura directa del
  sensor. Se marca solo en el momento en que el sensor PASA a detectar agua
  (no genera una alarma por cada lectura mientras el problema sigue activo).

## ✅ Confirmaciones de Emiliano (14/08/2026)

- **Campo `variacion`** = la carga informada por el surtidor, reportada por el
  sensor del propio dispositivo. Confirma lo que ya veníamos usando.
- **WIF**: booleano 0/1 (ver arriba).
- **Habrá una "API 2" a futuro** que traerá el listado completo de equipos
  (activo, cliente, etc.) — hoy la flota se carga a mano desde el excel que
  pasó Emiliano (`scripts/importar_equipos.php`), pero el día que esa API
  esté lista, se puede automatizar el alta de equipos nuevos sin intervención
  manual. Falta que Emiliano defina la estructura del JSON y el método de
  autenticación (Bearer token fijo, según lo conversado).

---

## 🗺️ Lo que falta

- [ ] Reconectar con la API real apenas Emiliano confirme que anda de nuevo (apagar
  `API_SIMULATE`).
- [ ] Ajustar paleta/logo a la marca real de preciso.tech.
- [x] ~~Cargar la flota completa.~~ Hecho — 1241 equipos.
- [ ] Definir dónde vive esto en producción definitiva (¿subdominio, subcarpeta,
  servidor aparte?).
- [ ] Sumar autenticación a la API si corresponde.
- [ ] Alertas por WhatsApp, si las quieren.
- [x] ~~Exportar informe a PDF.~~ Hecho — botón en cada informe.
- [ ] Calibrar el campo `variacion` con un caso real (ver punto 7 de arriba).
- [ ] Sumar un Volume en Railway si esto pasa de demo a algo que se use en serio (para
  no perder el historial en cada redeploy) — con 1241 equipos generando historial,
  esto pasa de "nice to have" a necesario bastante rápido.
- [ ] **Entrenar el modelo de ML de verdad** — necesita semanas de historial real
  acumulado de toda la flota. El reloj arranca desde que el cron esté corriendo en
  producción de forma continua, no antes.
- [x] ~~Confirmar con Emiliano si existe un endpoint para listar equipos activos.~~
  Confirmado: sí va a haber una "API 2" — falta la estructura del JSON y el método de
  autenticación.
- [x] ~~Sistema de alarma/badge que sube con anomalías nuevas y baja al resolverlas.~~
  Hecho — probado con navegador real, ciclo completo.
- [x] ~~Detección de agua en combustible (WIF).~~ Hecho — 4to tipo de anomalía.
- [ ] Confirmar el nombre EXACTO del campo WIF en el JSON real (hoy tiene varios alias
  candidatos, sin confirmar con datos reales).
- [ ] Definir con Emiliano la estructura de la "API 2" (listado de equipos) y el método
  de autenticación (Bearer token fijo, según lo conversado).

---

## 📁 Más detalle técnico

`backend/README.md` tiene la estructura completa, cómo funciona la detección de
anomalías paso a paso, y cómo montar los cron jobs para que esto ande solo (informes
automáticos + alertas).

Cualquier cosa, ya sabés dónde encontrarme.

— Roberto / Puma Code
