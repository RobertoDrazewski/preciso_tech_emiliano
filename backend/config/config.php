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
    ],

    // --- Base local ---
    'db' => [
        'path' => __DIR__ . '/../' . Env::get('DB_PATH', 'storage/preciso.sqlite'),
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
        'z_threshold' => 3.0,

        // Mínimo de caídas confirmadas en el historial antes de confiar en
        // el z-score. Con menos que esto, se usa solo el umbral absoluto.
        'min_history_points' => 20,

        // Regla de seguridad que funciona desde el primer día, sin historial:
        // caída CONFIRMADA (no recuperada) de más de esta cantidad de LITROS
        // en menos de esta cantidad de minutos. Ajustar según los vehículos
        // reales de la flota (un camión y una camioneta no consumen igual).
        'min_drop_liters_instant'  => 15.0,
        'min_drop_window_minutes'  => 10,

        // Si el vehículo está detenido (sin movimiento) y la caída CONFIRMADA
        // supera esta cantidad de litros, se considera sospechosa (parado no
        // debería perder combustible).
        'stopped_drop_liters_threshold' => 4.0,
    ],

    // --- Ventanas de tiempo por tipo de informe ---
    'reports' => [
        'daily_days'    => 1,
        'weekly_days'   => 7,
        'monthly_days'  => 30,
    ],
];
