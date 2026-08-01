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
    UNIQUE(equipo, fecha)
);

CREATE INDEX IF NOT EXISTS idx_lecturas_equipo_fecha ON lecturas(equipo, fecha);

CREATE TABLE IF NOT EXISTS anomalias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipo TEXT NOT NULL,
    fecha TEXT NOT NULL,
    tipo TEXT NOT NULL,             -- 'descarga_detenido', 'consumo_anomalo', 'caida_instantanea'
    detalle TEXT,
    litros_perdidos REAL,
    z_score REAL,
    lat REAL,
    lng REAL,
    alertado INTEGER NOT NULL DEFAULT 0,
    creado_en TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_anomalias_equipo_fecha ON anomalias(equipo, fecha);
