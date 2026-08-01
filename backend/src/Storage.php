<?php

class Storage
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $isNew = !file_exists($dbPath);

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');

        if ($isNew || $this->needsSchema()) {
            $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
            $this->pdo->exec($schema);
        }

        $this->migrarColumnasNuevas();
    }

    private function needsSchema(): bool
    {
        $res = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='lecturas'");
        return $res->fetch() === false;
    }

    /**
     * Migración liviana: si venís de una base creada antes de sumar
     * tanque1-4/variacion (o de sacar combustible_pct), agrega las columnas
     * que falten en vez de exigir borrar la base a mano. Bases muy viejas
     * con "combustible_pct" se pueden seguir usando tal cual, esa columna
     * simplemente queda sin uso.
     */
    private function migrarColumnasNuevas(): void
    {
        $columnasLecturas = array_column($this->pdo->query('PRAGMA table_info(lecturas)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        $nuevasLecturas = [
            'evento'          => 'TEXT',
            'tanque1_litros'  => 'REAL',
            'tanque2_litros'  => 'REAL',
            'tanque3_litros'  => 'REAL',
            'tanque4_litros'  => 'REAL',
            'variacion'       => 'REAL',
        ];

        foreach ($nuevasLecturas as $columna => $tipo) {
            if (!in_array($columna, $columnasLecturas, true)) {
                $this->pdo->exec("ALTER TABLE lecturas ADD COLUMN {$columna} {$tipo}");
            }
        }

        $columnasAnomalias = array_column($this->pdo->query('PRAGMA table_info(anomalias)')->fetchAll(PDO::FETCH_ASSOC), 'name');

        $nuevasAnomalias = [
            'lat' => 'REAL',
            'lng' => 'REAL',
        ];

        foreach ($nuevasAnomalias as $columna => $tipo) {
            if (!in_array($columna, $columnasAnomalias, true)) {
                $this->pdo->exec("ALTER TABLE anomalias ADD COLUMN {$columna} {$tipo}");
            }
        }
    }

    /** Guarda lecturas normalizadas, ignorando duplicados (equipo+fecha ya existentes). */
    public function guardarLecturas(array $lecturas): int
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO lecturas
                (equipo, fecha, evento, lat, lng, velocidad, combustible_litros,
                 tanque1_litros, tanque2_litros, tanque3_litros, tanque4_litros,
                 variacion, odometro_km, motor_encendido)
            VALUES
                (:equipo, :fecha, :evento, :lat, :lng, :velocidad, :combustible_litros,
                 :tanque1_litros, :tanque2_litros, :tanque3_litros, :tanque4_litros,
                 :variacion, :odometro_km, :motor_encendido)
        ');

        $count = 0;
        $this->pdo->beginTransaction();
        foreach ($lecturas as $l) {
            $tanques = $l['tanques'] ?? [];
            $stmt->execute([
                ':equipo'             => $l['equipo'],
                ':fecha'              => $l['fecha']->format(DateTimeInterface::ATOM),
                ':evento'             => $l['evento'] ?? null,
                ':lat'                => $l['lat'],
                ':lng'                => $l['lng'],
                ':velocidad'          => $l['velocidad'],
                ':combustible_litros' => $l['combustible_litros'],
                ':tanque1_litros'     => $tanques[1] ?? null,
                ':tanque2_litros'     => $tanques[2] ?? null,
                ':tanque3_litros'     => $tanques[3] ?? null,
                ':tanque4_litros'     => $tanques[4] ?? null,
                ':variacion'          => $l['variacion'] ?? null,
                ':odometro_km'        => $l['odometro_km'],
                ':motor_encendido'    => $l['motor_encendido'] ? 1 : 0,
            ]);
            $count += $stmt->rowCount();
        }
        $this->pdo->commit();

        return $count;
    }

    /**
     * Lecturas de un equipo en un rango, con tipos ya convertidos (fecha como
     * DateTimeImmutable, numéricos como float/bool). Es lo que usa
     * AnomalyDetector para poder mirar hacia adelante y hacia atrás de cada
     * caída de combustible.
     */
    public function lecturasTypedEnRango(string $equipo, DateTimeInterface $desde, DateTimeInterface $hasta): array
    {
        $rows = $this->lecturasEnRango($equipo, $desde, $hasta);

        return array_map(function (array $r) {
            return [
                'equipo'             => $r['equipo'],
                'fecha'              => new DateTimeImmutable($r['fecha']),
                'evento'             => $r['evento'] ?? null,
                'lat'                => $r['lat'] !== null ? (float) $r['lat'] : null,
                'lng'                => $r['lng'] !== null ? (float) $r['lng'] : null,
                'velocidad'          => (float) $r['velocidad'],
                'combustible_litros' => $r['combustible_litros'] !== null ? (float) $r['combustible_litros'] : null,
                'tanques' => [
                    1 => isset($r['tanque1_litros']) && $r['tanque1_litros'] !== null ? (float) $r['tanque1_litros'] : null,
                    2 => isset($r['tanque2_litros']) && $r['tanque2_litros'] !== null ? (float) $r['tanque2_litros'] : null,
                    3 => isset($r['tanque3_litros']) && $r['tanque3_litros'] !== null ? (float) $r['tanque3_litros'] : null,
                    4 => isset($r['tanque4_litros']) && $r['tanque4_litros'] !== null ? (float) $r['tanque4_litros'] : null,
                ],
                'variacion'          => isset($r['variacion']) && $r['variacion'] !== null ? (float) $r['variacion'] : null,
                'odometro_km'        => $r['odometro_km'] !== null ? (float) $r['odometro_km'] : null,
                'motor_encendido'    => (bool) $r['motor_encendido'],
            ];
        }, $rows);
    }

    public function guardarAnomalia(array $a): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO anomalias (equipo, fecha, tipo, detalle, litros_perdidos, z_score, lat, lng, alertado, creado_en)
            VALUES (:equipo, :fecha, :tipo, :detalle, :litros_perdidos, :z_score, :lat, :lng, 0, :creado_en)
        ');
        $stmt->execute([
            ':equipo'          => $a['equipo'],
            ':fecha'           => $a['fecha']->format(DateTimeInterface::ATOM),
            ':tipo'            => $a['tipo'],
            ':detalle'         => $a['detalle'],
            ':litros_perdidos' => $a['litros_perdidos'],
            ':z_score'         => $a['z_score'],
            ':lat'             => $a['lat'] ?? null,
            ':lng'             => $a['lng'] ?? null,
            ':creado_en'       => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Evita alertar dos veces la misma anomalía (mismo equipo+fecha+tipo). */
    public function anomaliaYaRegistrada(string $equipo, DateTimeInterface $fecha, string $tipo): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT id FROM anomalias WHERE equipo = :equipo AND fecha = :fecha AND tipo = :tipo LIMIT 1
        ');
        $stmt->execute([
            ':equipo' => $equipo,
            ':fecha'  => $fecha->format(DateTimeInterface::ATOM),
            ':tipo'   => $tipo,
        ]);
        return $stmt->fetch() !== false;
    }

    public function marcarAlertada(int $anomaliaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE anomalias SET alertado = 1 WHERE id = :id');
        $stmt->execute([':id' => $anomaliaId]);
    }

    public function anomaliasPendientesDeAlerta(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM anomalias WHERE alertado = 0 ORDER BY fecha ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function anomaliasEnRango(string $equipo, DateTimeInterface $desde, DateTimeInterface $hasta): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM anomalias
            WHERE equipo = :equipo AND fecha BETWEEN :desde AND :hasta
            ORDER BY fecha ASC
        ');
        $stmt->execute([
            ':equipo' => $equipo,
            ':desde'  => $desde->format(DateTimeInterface::ATOM),
            ':hasta'  => $hasta->format(DateTimeInterface::ATOM),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lecturasEnRango(string $equipo, DateTimeInterface $desde, DateTimeInterface $hasta): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM lecturas
            WHERE equipo = :equipo AND fecha BETWEEN :desde AND :hasta
            ORDER BY fecha ASC
        ');
        $stmt->execute([
            ':equipo' => $equipo,
            ':desde'  => $desde->format(DateTimeInterface::ATOM),
            ':hasta'  => $hasta->format(DateTimeInterface::ATOM),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
