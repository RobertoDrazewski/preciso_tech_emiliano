-- Mismo esquema que schema.sql (SQLite), traducido a sintaxis de Postgres.
-- Se usa automáticamente cuando hay DATABASE_URL (ver config/config.php).
-- Mantener las dos en sync si se agrega/cambia algo — no hay una sola
-- fuente de verdad por las diferencias de sintaxis entre los dos motores
-- (AUTOINCREMENT vs SERIAL, etc.)

CREATE TABLE IF NOT EXISTS lecturas (
    id SERIAL PRIMARY KEY,
    equipo TEXT NOT NULL,
    fecha TEXT NOT NULL,          -- ISO 8601 (como texto, igual que en SQLite — evita líos de timezone entre los dos motores)
    evento TEXT,                  -- 'DATA' | 'TIEMPO' | otro
    lat DOUBLE PRECISION,
    lng DOUBLE PRECISION,
    velocidad DOUBLE PRECISION,
    combustible_litros DOUBLE PRECISION,
    tanque1_litros DOUBLE PRECISION,
    tanque2_litros DOUBLE PRECISION,
    tanque3_litros DOUBLE PRECISION,
    tanque4_litros DOUBLE PRECISION,
    variacion DOUBLE PRECISION,
    odometro_km DOUBLE PRECISION,
    motor_encendido INTEGER,
    wif INTEGER,
    altitud DOUBLE PRECISION,
    temp_tanque DOUBLE PRECISION,
    UNIQUE(equipo, fecha)
);

CREATE INDEX IF NOT EXISTS idx_lecturas_equipo_fecha ON lecturas(equipo, fecha);

CREATE TABLE IF NOT EXISTS anomalias (
    id SERIAL PRIMARY KEY,
    equipo TEXT NOT NULL,
    fecha TEXT NOT NULL,
    tipo TEXT NOT NULL,
    detalle TEXT,
    litros_perdidos DOUBLE PRECISION,
    z_score DOUBLE PRECISION,
    lat DOUBLE PRECISION,
    lng DOUBLE PRECISION,
    alertado INTEGER NOT NULL DEFAULT 0,
    resuelta INTEGER NOT NULL DEFAULT 0,
    resuelta_en TEXT,
    creado_en TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_anomalias_equipo_fecha ON anomalias(equipo, fecha);

CREATE TABLE IF NOT EXISTS equipos (
    id TEXT PRIMARY KEY,
    nombre TEXT NOT NULL,
    activo INTEGER NOT NULL DEFAULT 1,
    cliente TEXT,
    estado_raw TEXT,
    tipo TEXT,
    marca TEXT,
    modelo TEXT,
    capacidad_litros DOUBLE PRECISION,
    diagnostico TEXT,
    servicio TEXT,
    creado_en TEXT NOT NULL,
    actualizado_en TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS estado (
    clave TEXT PRIMARY KEY,
    valor TEXT
);
