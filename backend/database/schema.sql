CREATE TABLE IF NOT EXISTS lecturas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo TEXT NOT NULL,
    fecha TEXT NOT NULL,          -- ISO 8601
    evento TEXT,                  -- 'DATA' | 'TIEMPO' | otro
    lat REAL,
    lng REAL,
    velocidad REAL,
    combustible_litros REAL,      -- "nivel total", el campo autoritativo (litros absolutos, no hay % de tanque)
    tanque1_litros REAL,
    tanque2_litros REAL,
    tanque3_litros REAL,
    tanque4_litros REAL,
    variacion REAL,                -- señal del dispositivo: !=0 cuando detectó carga/descarga
    odometro_km REAL,
    motor_encendido INTEGER,
    wif INTEGER,                   -- Water In Fuel: 1 = agua detectada en el combustible, 0/NULL = sin dato
    altitud REAL,                  -- metros, viene en el JSON real (22/09/2026)
    temp_tanque REAL,              -- °C del tanque, para la anomalía temperatura/nivel
    UNIQUE(equipo, fecha)
);

CREATE INDEX IF NOT EXISTS idx_lecturas_equipo_fecha ON lecturas(equipo, fecha);

CREATE TABLE IF NOT EXISTS anomalias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo TEXT NOT NULL,
    fecha TEXT NOT NULL,
    tipo TEXT NOT NULL,             -- 'descarga_detenido', 'consumo_anomalo', 'caida_instantanea', 'agua_combustible'
    detalle TEXT,
    litros_perdidos REAL,
    z_score REAL,
    lat REAL,
    lng REAL,
    alertado INTEGER NOT NULL DEFAULT 0,
    resuelta INTEGER NOT NULL DEFAULT 0,   -- se marca en 1 cuando alguien descarga/manda el PDF que la incluye
    resuelta_en TEXT,
    estado_revision TEXT NOT NULL DEFAULT 'pendiente',  -- pendiente | validada | descartada
    revisada_en TEXT,
    comentario_revision TEXT,
    creado_en TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_anomalias_equipo_fecha ON anomalias(equipo, fecha);

-- La flota ya NO vive en un archivo de config fijo: los equipos se pueden ir
-- sumando con el tiempo (instalaciones nuevas de Emiliano), así que viven acá,
-- en la base, para poder agregarlos sin tocar código ni redeployar.
CREATE TABLE IF NOT EXISTS equipos (
    id TEXT PRIMARY KEY,           -- id del equipo tal como lo espera la API de TTM
    nombre TEXT NOT NULL,          -- patente / nombre para mostrar
    activo INTEGER NOT NULL DEFAULT 1,
    -- Campos que trae la API real de equipos de Emiliano (get_devices.php,
    -- confirmada 26/08/2026). 'estado_raw' guarda el texto tal cual
    -- (ACTIVO/INACTIVO/SUSPENDIDO) — 'activo' arriba es la versión
    -- simplificada que usa el resto del sistema para decidir si lo
    -- monitorea o no (SUSPENDIDO cuenta como inactivo para nosotros).
    cliente TEXT,
    estado_raw TEXT,
    tipo TEXT,
    marca TEXT,
    modelo TEXT,
    capacidad_litros REAL,         -- capacidad del tanque, si la API la tiene cargada (0 = sin dato)
    diagnostico TEXT,               -- viene de la API de equipos (ej: "OK", "AGUA", "NO REPORTA") — no es histórico, es el estado actual que reporta TTM
    servicio TEXT,                  -- "FULL" | "STANDARD" | "DEMO" — nivel de servicio del cliente, para filtrar
    creado_en TEXT NOT NULL,
    actualizado_en TEXT NOT NULL
);

-- Estado simple clave/valor para cosas como el cursor de rotación del cron
-- (con una flota grande, no se puede consultar la API de todos los equipos
-- en cada corrida — ver cron/check_alertas.php).
CREATE TABLE IF NOT EXISTS estado (
    clave TEXT PRIMARY KEY,
    valor TEXT
);
