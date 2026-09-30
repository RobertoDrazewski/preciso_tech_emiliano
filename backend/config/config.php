<?php

require_once __DIR__ . '/../src/Env.php';

Env::load(__DIR__ . '/../.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Argentina/Mendoza'));

return [

    // --- API de origen (TTM / Preciso) ---
    'api' => [
        'base_url' => Env::get('API_BASE_URL', 'https://ttm.com.ar/testing/api/get_full_data.php'),
        'token'    => Env::get('API_TOKEN', ''),
        'timeout'  => 20, // segundos
        'retries'  => 2,

        // ⚠️ MODO SIMULADOR — mientras la API real de ttm.com.ar esté caída
        // o inestable del lado de Emiliano, poné esto en "true" y el sistema
        // genera datos con la misma estructura exacta de la API real (no
        // datos inventados a lo loco: mismos campos, mismo comportamiento
        // DATA/TIEMPO, mismo ruido de sensor en los tanques). El resto del
        // sistema (normalizador, detector de anomalías, informes) corre
        // exactamente igual que con datos reales. Apenas Emiliano confirme
        // que su API anda, poné esto en "false" — no hay que tocar nada
        // más, ni una línea de código.
        'simulate' => filter_var(Env::get('API_SIMULATE', 'false'), FILTER_VALIDATE_BOOLEAN),

        // ⚠️ SOLO PARA DIAGNÓSTICO LOCAL, NUNCA EN PRODUCCIÓN ⚠️
        // El certificado SSL de ttm.com.ar (al 23/07/2026) no manda la cadena
        // intermedia completa, así que curl no puede validarlo aunque el
        // certificado en sí sea válido. Poner API_INSECURE_SSL_TESTING=true
        // en tu .env local para saltear la verificación mientras Emiliano
        // arregla el certificado del lado del servidor. Sacar esta bandera
        // (o dejarla en false) antes de ir a producción.
        'insecure_ssl_testing' => filter_var(Env::get('API_INSECURE_SSL_TESTING', 'false'), FILTER_VALIDATE_BOOLEAN),

        // API de EQUIPOS (flota completa con datos administrativos: cliente,
        // estado, capacidad de tanque, etc.) — confirmada por Emiliano el
        // 26/08/2026. Endpoint DISTINTO al de telemetría de arriba.
        'devices_url' => Env::get('DEVICES_API_URL', 'https://ttm.com.ar/testing/api/get_devices.php'),
    ],

    // --- Base local ---
    // --- Base de datos ---
    // Si Railway (u otro hosting) inyecta DATABASE_URL (al linkear un
    // servicio de Postgres), se usa Postgres automáticamente — necesario
    // porque el disco de un contenedor es efímero (se borra en cada
    // redeploy) y con 1000+ equipos generando historial, perder todo en
    // cada deploy no es viable. En tu máquina local, sin esa variable,
    // sigue usando SQLite tal cual — no hace falta instalar Postgres para
    // desarrollar.
    'db' => [
        'driver' => Env::get('DATABASE_URL', '') !== '' ? 'pgsql' : 'sqlite',
        'path'   => __DIR__ . '/../' . Env::get('DB_PATH', 'storage/preciso.sqlite'),
        'url'    => Env::get('DATABASE_URL', ''),
    ],

    // --- SMTP para alertas ---
    'smtp' => [
        'host'      => Env::get('SMTP_HOST', 'smtp.gmail.com'),
        'port'      => (int) Env::get('SMTP_PORT', 587),
        'user'      => Env::get('SMTP_USER', ''),
        'pass'      => Env::get('SMTP_PASS', ''),
        'from'      => Env::get('SMTP_FROM', ''),
        'from_name' => Env::get('SMTP_FROM_NAME', 'Preciso - Alertas de Combustible'),
        'to'        => array_filter(array_map('trim', explode(',', Env::get('ALERT_EMAILS', '')))),
    ],

    // --- Umbrales de detección de anomalías ---
    // ⚠️ Emiliano confirmó (27/07/2026): no existe la capacidad de tanque en
    // ningún lado, todo se maneja en litros absolutos. Por eso TODOS los
    // umbrales de acá son en litros, nunca en % de un tanque que no existe.
    // Ajustar con datos reales una vez que haya al menos 1-2 semanas de historial.
    'anomaly' => [
        // Ventana de historial que se usa para calcular media/desvío estándar
        // de las caídas de combustible de cada equipo.
        'history_window_hours' => 168, // 7 días

        // ⚠️ Filtro de "rebote de pendiente" (pedido explícito de Emiliano):
        // una caída de combustible NO se marca como anomalía en el momento.
        // Primero se espera esta cantidad de minutos; si el nivel se
        // recupera solo (subida/bajada que nivela el tanque), no es una
        // anomalía real y se descarta. Si no se recupera, ahí sí se evalúa
        // con las reglas de abajo.
        'recovery_window_minutes' => 20,

        // Fracción de la caída que tiene que recuperarse dentro de la
        // ventana de arriba para considerarla "rebote" y no caída real.
        // 0.7 = si recuperó al menos el 70% de lo que había caído, se
        // descarta como pendiente/ruido de sensor.
        'recovery_min_fraction' => 0.7,

        // z-score a partir del cual una caída YA CONFIRMADA (no recuperada)
        // se considera anómala (comparado contra el historial propio de
        // caídas confirmadas de ese vehículo, en L/min).
        // Subido de 3 a 4 el 29/09/2026: con 3 salían más de 1.300 por
        // semana, muchas de 4-6 L. Se puede cambiar desde la pantalla.
        'z_threshold' => 4.0,
        // Y además un piso de litros: una caída de 4 L puede ser
        // "estadísticamente rara" y aun así no le importa a nadie.
        'consumo_anomalo_litros_minimos' => 10.0,

        // Mínimo de caídas confirmadas en el historial antes de confiar en
        // el z-score. Con menos que esto, se usa solo el umbral absoluto.
        'min_history_points' => 20,

        // Regla de seguridad que funciona desde el primer día, sin historial:
        // caída CONFIRMADA (no recuperada) de más de esta cantidad de LITROS
        // en menos de esta cantidad de minutos. Ajustar según los vehículos
        // reales de la flota (un camión y una camioneta no consumen igual).
        'min_drop_liters_instant'  => 15.0,
        'min_drop_window_minutes'  => 10,

        // Si el vehículo está detenido y la caída CONFIRMADA supera esta
        // cantidad de litros, se considera sospechosa (parado no debería
        // perder combustible).
        //
        // Estaba en 4 L y lo subí a 15 L el 24/09/2026: al reprocesar los
        // últimos 30 días, más de la mitad de las "descargas detenido"
        // eran de menos de 10 L. Eso es el ruido propio del sensor (el
        // nivel "tiembla" unos litros aunque nadie toque nada), no robos.
        // Un robo real rara vez es de menos de 15 L.
        'stopped_drop_liters_threshold' => 15.0,

        // Equipos que NO se mueven nunca: tanques fijos, pozos, máquinas,
        // generadores. Para estos no aplican "descarga detenido", "caída
        // instantánea" ni "ralentí": un tanque fijo está siempre quieto y
        // su trabajo es que le saquen combustible para cargar camiones; un
        // pozo tiene un motor que consume todo el día sin moverse. Al
        // reprocesar, 9 de los 10 equipos con más "descargas" eran de
        // estos — el 44% del total, todo falso.
        //
        // Se detectan por el "tipo" que manda la API de equipos de TTM, y
        // si el tipo no dice nada, por el nombre (la carga de datos de TTM
        // no siempre es prolija). Se compara sin importar mayúsculas y
        // alcanza con que el texto CONTENGA alguna de estas palabras.
        //
        // El 24/09/2026 corrí diagnostico_apis.php y aparecieron tipos que
        // no conocíamos: "equipo frio" (117 equipos: el motor de frío
        // consume aunque el camión esté parado, así que "descarga detenido"
        // y "ralentí" siempre darían falso), "tanque stock" y "tanque
        // movil" (entregan combustible, igual que un tanque fijo).
        'palabras_equipo_estacionario' => ['tanque fijo', 'tanquefijo', 'tanque stock', 'tanque movil', 'tanque móvil', 'equipo frio', 'equipo frío', 'pozo', 'maquina', 'máquina', 'generador'],

        // --- Controles que agregué el 23/09/2026 mirando la lista real ---

        // Hueco máximo entre dos lecturas para compararlas. Si entre una
        // lectura y la siguiente pasaron más de 30 minutos (sensor sin
        // dato, equipo apagado, falta de señal), no tengo idea de qué pasó
        // en el medio: pudo haber cargado, consumido andando, cualquier
        // cosa. Antes comparaba igual y salían cosas como "perdió 837 L
        // entre 11:51 y 11:47" — con dos días de hueco entre las lecturas.
        'max_gap_minutos' => 30,

        // Velocidad máxima a la que se puede sacar combustible de verdad.
        // Una pistola de surtidor de camión anda entre 40 y 130 L/min; una
        // bomba de robo, menos. Si una caída es más rápida que esto, no es
        // combustible saliendo del tanque: es el sensor que se desconectó
        // o se reinició. No la tiro — la marco como "desconexión de
        // sensor", que era justamente el punto 5 que pidió Emiliano.
        'tasa_maxima_fisica_lpm' => 150.0,

        // Para decir que un vehículo estaba DETENIDO no alcanza con el
        // campo "movimiento" (en el JSON real vimos movimiento=0 con el
        // camión a 9 km/h). Pido velocidad casi 0 en las dos lecturas Y
        // que no se haya movido más de esta distancia. Así no confundo el
        // consumo normal subiendo la cordillera con una descarga.
        // --- Corte de alimentación (POWOFF / POWON) ---
        // Emiliano confirmó que TTM manda los eventos POWOFF (se cortó la
        // alimentación del equipo) y POWON (volvió). Cortar la alimentación
        // es el truco clásico para robar sin que el sensor registre la
        // descarga: se apaga, se saca, se vuelve a conectar. Por eso
        // comparo el último nivel antes del corte con el primero después.
        // Busco esas lecturas hasta esta cantidad de minutos antes del
        // POWOFF y después del POWON.
        'corte_margen_lectura_minutos' => 60,
        // Si al volver faltan al menos estos litros, lo marco como posible
        // robo durante el corte (uso el mismo criterio que para descarga
        // detenido: por debajo es ruido del sensor).
        'corte_litros_sospechosos' => 15.0,
        // ¿Reportar también los cortes SIN pérdida de combustible? En false
        // desde el 25/09/2026: reprocesando 30 días salieron 14.032 cortes,
        // casi todos del chofer cortando la batería al terminar el turno
        // (normal, todos los días). Lo que importa es el corte después del
        // cual FALTA combustible. Si algún día quieren ver todos, true.
        'corte_reportar_sin_perdida' => false,

        // --- Carga informada vs. medida (punto 3 de Emiliano) ---
        // TTM manda un evento CARGA con la cantidad informada en el campo
        // "variacion" (Emiliano: "la carga informada viene en variacion").
        // Comparo eso contra lo que realmente subió el nivel: el nivel
        // antes de la carga contra el más alto que alcanzó después, cuando
        // el combustible ya se asentó en el tanque.
        'carga_margen_lectura_minutos'   => 60,   // hasta dónde busco el nivel de antes
        'carga_ventana_asentamiento_min' => 30,   // cuánto espero después para medir cuánto subió
        // Una carga real puede venir partida en varios eventos CARGA seguidos
        // (vimos 24, 19 y 32 L en cuatro minutos para una sola carga). Los
        // que están a menos de estos minutos uno del otro son UNA carga.
        'carga_agrupar_minutos'          => 30,
        // Para medir la carga como el sensor: nivel estable antes contra
        // nivel estable después. Una lectura cuenta como "estable" si está a
        // menos de estos litros del mínimo (antes) o del máximo (después).
        'carga_tolerancia_meseta_litros' => 3.0,
        // Diferencia mínima para avisar: tiene que superar las DOS cosas
        // (así no salta por 3 L en una carga chica ni por 10 L en una de 400).
        'carga_diferencia_min_litros'    => 10.0,
        'carga_diferencia_min_porcentaje' => 0.15,
        // ¿Avisar también cuando entró MÁS de lo informado? En 0 (no) desde
        // el 30/09/2026: reprocesando 30 días, el 80% de las diferencias eran
        // "entró más", un desbalance que apunta a algo sistemático de cómo se
        // informa la carga, a calibrar con TTM. El caso que cuesta plata es
        // "entró MENOS" (se pagó combustible que no está en el tanque), y ese
        // se avisa siempre. Se puede activar desde la pantalla.
        'carga_avisar_si_entro_mas' => 0,

        'detenido_velocidad_max_kmh' => 1.0,
        'detenido_distancia_max_metros' => 200,

        // --- Ralentí (motor encendido + detenido + consumiendo) ---
        // A propósito, esto NO usa GPS/posición — Emiliano lo pidió así
        // porque muchos equipos quedan bajo techo (el GPS ahí no sirve) y
        // porque motor+velocidad+consumo ya alcanza para detectarlo bien.
        // Un bloque de ralentí tiene que durar al menos esto para contar
        // (así no se marca cada semáforo en rojo como "ralentí").
        // 30 min (antes 15): calentar el motor o esperar una carga 15-20
        // minutos es normal en un camión. Se puede cambiar desde la pantalla.
        'ralenti_minutos_minimos' => 30,
        // Y tiene que haber consumido al menos esto en ese bloque — sin
        // este piso, ruido de sensor en un motor apagado-pero-marcado-
        // encendido por error podría generar falsos positivos.
        'ralenti_litros_minimos' => 1.0,

        // --- Temperatura / nivel (punto 7 que pidió Emiliano) ---
        // El gasoil se dilata con el calor: más o menos 0,083% de su
        // volumen por cada grado. O sea que un tanque con 600 L que pasa
        // de 10 °C a 30 °C "crece" unos 10 L sin que nadie le cargue nada.
        // Eso es normal y lo tengo que aceptar. Lo que NO es normal es que
        // el nivel medido siga a la temperatura mucho más de lo que
        // explica la dilatación — ahí el sensor está afectado por la
        // temperatura (mal calibrado, mal instalado) y sus lecturas no son
        // confiables. Esta anomalía avisa eso.
        //
        // Cómo lo mido: con el vehículo quieto (sin consumo), cruzo la
        // curva de temperatura contra la de nivel y saco la correlación.
        // Si van muy juntas Y la variación es varias veces mayor a la que
        // explica la dilatación, lo marco.
        'temp_coef_dilatacion'         => 0.00083, // por °C, gasoil
        'temp_correlacion_minima'      => 0.8,     // qué tan "pegadas" tienen que ir las dos curvas (1 = idénticas)
        'temp_factor_exceso'           => 3.0,     // cuántas veces más que la dilatación normal tiene que variar el nivel
        'temp_variacion_minima_litros' => 5.0,     // por debajo de esto no vale la pena avisar
        'temp_rango_minimo_grados'     => 5.0,     // si la temperatura casi no se movió, no hay nada para cruzar
        'temp_min_lecturas'            => 30,      // con pocos puntos la correlación no dice nada
    ],

    // --- Ventanas de tiempo por tipo de informe ---
    'reports' => [
        'daily_days'    => 1,
        'weekly_days'   => 7,
        'monthly_days'  => 30,
    ],

    // --- Cron con flota grande ---
    // Con más de mil equipos, NO se puede consultar la API de todos en
    // cada corrida del cron (ni por tiempo, ni por respeto a la API de
    // Emiliano, que además es de prueba). check_alertas.php procesa un
    // lote por corrida, rotando por toda la flota — con este número y un
    // cron cada 5 min, un lote de 100 cubre ~1250 equipos en poco más de
    // una hora. Ajustar según cuánto aguante la API real en producción.
    'cron' => [
        'batch_size' => 100,
    ],
];
