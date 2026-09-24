<?php

/**
 * Capa de datos. Soporta dos motores:
 *
 *  - SQLite: default en tu máquina local (nada que instalar).
 *  - Postgres: se usa solo cuando config['db']['driver'] === 'pgsql', que
 *    pasa automáticamente cuando hay DATABASE_URL (Railway, al linkear un
 *    servicio de Postgres — ver config/config.php). Necesario en producción
 *    porque el disco de un contenedor es efímero: sin una base "de verdad"
 *    afuera del contenedor, se pierde todo el historial en cada redeploy.
 *
 * El resto del sistema (AnomalyDetector, ReportBuilder, los endpoints) no
 * se entera de cuál de los dos está usando — todos los métodos públicos
 * son iguales para los dos motores.
 */
class Storage
{
    private PDO $pdo;
    private string $driver; // 'sqlite' | 'pgsql'

    public function __construct(array $dbConfig)
    {
        $this->driver = $dbConfig['driver'] ?? 'sqlite';

        if ($this->driver === 'pgsql') {
            $this->conectarPostgres($dbConfig['url']);
        } else {
            $this->conectarSqlite($dbConfig['path']);
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($this->needsSchema()) {
            $archivoEsquema = $this->driver === 'pgsql' ? 'schema_postgres.sql' : 'schema.sql';
            $schema = file_get_contents(__DIR__ . '/../database/' . $archivoEsquema);
            $this->pdo->exec($schema);
        }

        $this->migrarTablaEquipos();
        $this->migrarTablaEstado();
        $this->migrarColumnasNuevas();
    }

    private function conectarSqlite(string $dbPath): void
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
    }

    private function conectarPostgres(string $databaseUrl): void
    {
        // Railway (y la mayoría de los hostings) dan la conexión como URL
        // tipo postgres://usuario:pass@host:puerto/basededatos — PDO no
        // acepta esa URL directo, hay que parsearla a un DSN pgsql:.
        $partes = parse_url($databaseUrl);
        if ($partes === false || !isset($partes['host'])) {
            throw new RuntimeException('DATABASE_URL no es una URL de Postgres válida.');
        }

        $host = $partes['host'];
        $puerto = $partes['port'] ?? 5432;
        $baseDeDatos = ltrim($partes['path'] ?? '', '/');
        $usuario = $partes['user'] ?? '';
        $clave = $partes['pass'] ?? '';

        $dsn = "pgsql:host={$host};port={$puerto};dbname={$baseDeDatos}";
        $this->pdo = new PDO($dsn, $usuario, $clave);
    }

    private function needsSchema(): bool
    {
        if ($this->driver === 'pgsql') {
            $res = $this->pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = 'lecturas'");
        } else {
            $res = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='lecturas'");
        }
        return $res->fetch() === false;
    }

    private function columnasDe(string $tabla): array
    {
        if ($this->driver === 'pgsql') {
            $stmt = $this->pdo->prepare("SELECT column_name AS name FROM information_schema.columns WHERE table_name = :tabla");
            $stmt->execute([':tabla' => $tabla]);
        } else {
            $stmt = $this->pdo->query("PRAGMA table_info({$tabla})");
        }
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    private function tablaExiste(string $tabla): bool
    {
        if ($this->driver === 'pgsql') {
            $stmt = $this->pdo->prepare("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = :tabla");
        } else {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :tabla");
        }
        $stmt->execute([':tabla' => $tabla]);
        return $stmt->fetch() !== false;
    }

    /**
     * Migración liviana: si venís de una base creada antes de sumar
     * tanque1-4/variacion (o de sacar combustible_pct), agrega las columnas
     * que falten en vez de exigir borrar la base a mano.
     */
    private function migrarColumnasNuevas(): void
    {
        $tipoReal = $this->driver === 'pgsql' ? 'DOUBLE PRECISION' : 'REAL';

        $columnasLecturas = $this->columnasDe('lecturas');
        $nuevasLecturas = [
            'evento'          => 'TEXT',
            'tanque1_litros'  => $tipoReal,
            'tanque2_litros'  => $tipoReal,
            'tanque3_litros'  => $tipoReal,
            'tanque4_litros'  => $tipoReal,
            'variacion'       => $tipoReal,
            'wif'             => 'INTEGER',
            'altitud'         => $tipoReal,
            'temp_tanque'     => $tipoReal,
        ];
        foreach ($nuevasLecturas as $columna => $tipo) {
            if (!in_array($columna, $columnasLecturas, true)) {
                $this->pdo->exec("ALTER TABLE lecturas ADD COLUMN {$columna} {$tipo}");
            }
        }

        $columnasAnomalias = $this->columnasDe('anomalias');
        $nuevasAnomalias = [
            'lat'         => $tipoReal,
            'lng'         => $tipoReal,
            'resuelta'    => 'INTEGER NOT NULL DEFAULT 0',
            'resuelta_en' => 'TEXT',
        ];
        foreach ($nuevasAnomalias as $columna => $tipo) {
            if (!in_array($columna, $columnasAnomalias, true)) {
                $this->pdo->exec("ALTER TABLE anomalias ADD COLUMN {$columna} {$tipo}");
            }
        }

        // Campos que trae la API real de equipos de Emiliano (get_devices.php).
        $columnasEquipos = $this->columnasDe('equipos');
        $nuevasEquipos = [
            'cliente'           => 'TEXT',
            'estado_raw'        => 'TEXT',
            'tipo'              => 'TEXT',
            'marca'             => 'TEXT',
            'modelo'            => 'TEXT',
            'capacidad_litros'  => $tipoReal,
            'diagnostico'       => 'TEXT',
            'servicio'          => 'TEXT',
        ];
        foreach ($nuevasEquipos as $columna => $tipo) {
            if (!in_array($columna, $columnasEquipos, true)) {
                $this->pdo->exec("ALTER TABLE equipos ADD COLUMN {$columna} {$tipo}");
            }
        }
    }

    /** Si venís de una base creada antes de tener la tabla equipos, la crea. */
    private function migrarTablaEquipos(): void
    {
        if (!$this->tablaExiste('equipos')) {
            $this->pdo->exec("
                CREATE TABLE equipos (
                    id TEXT PRIMARY KEY,
                    nombre TEXT NOT NULL,
                    activo INTEGER NOT NULL DEFAULT 1,
                    creado_en TEXT NOT NULL,
                    actualizado_en TEXT NOT NULL
                )
            ");
        }
    }

    /** Si venís de una base creada antes de tener la tabla estado, la crea. */
    private function migrarTablaEstado(): void
    {
        if (!$this->tablaExiste('estado')) {
            $this->pdo->exec("CREATE TABLE estado (clave TEXT PRIMARY KEY, valor TEXT)");
        }
    }

    public function getEstado(string $clave): ?string
    {
        $stmt = $this->pdo->prepare('SELECT valor FROM estado WHERE clave = :clave');
        $stmt->execute([':clave' => $clave]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['valor'] : null;
    }

    public function setEstado(string $clave, string $valor): void
    {
        // ON CONFLICT ... DO UPDATE es sintaxis compartida por SQLite
        // (3.24+) y Postgres, no hace falta ramificar acá.
        $stmt = $this->pdo->prepare('
            INSERT INTO estado (clave, valor) VALUES (:clave, :valor)
            ON CONFLICT(clave) DO UPDATE SET valor = :valor
        ');
        $stmt->execute([':clave' => $clave, ':valor' => $valor]);
    }

    /**
     * Trae el próximo lote de equipos a procesar, rotando por toda la
     * flota entre corridas de cron (no se puede consultar la API de 1000+
     * equipos en una sola corrida). Guarda el cursor solo, así la próxima
     * llamada sigue donde dejó la anterior, y vuelve a empezar cuando llega
     * al final.
     */
    public function proximoLoteEquipos(int $tamanoLote): array
    {
        $cursor = $this->getEstado('cron_cursor_equipo') ?? '';

        $stmt = $this->pdo->prepare('
            SELECT id, nombre, capacidad_litros FROM equipos
            WHERE activo = 1 AND id > :cursor
            ORDER BY id ASC
            LIMIT :limite
        ');
        $stmt->bindValue(':cursor', $cursor);
        $stmt->bindValue(':limite', $tamanoLote, PDO::PARAM_INT);
        $stmt->execute();
        $lote = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($lote) < $tamanoLote) {
            $faltan = $tamanoLote - count($lote);
            $stmt2 = $this->pdo->prepare('
                SELECT id, nombre, capacidad_litros FROM equipos
                WHERE activo = 1
                ORDER BY id ASC
                LIMIT :limite
            ');
            $stmt2->bindValue(':limite', $faltan, PDO::PARAM_INT);
            $stmt2->execute();
            $lote = array_merge($lote, $stmt2->fetchAll(PDO::FETCH_ASSOC));
        }

        if (!empty($lote)) {
            $this->setEstado('cron_cursor_equipo', end($lote)['id']);
        }

        return $lote;
    }

    /**
     * Agrega o actualiza un equipo. Es la forma de sumar una instalación
     * nueva sin tocar código ni redeployar — correr scripts/agregar_equipo.php
     * (o llamar este método) apenas Emiliano avise de un equipo nuevo.
     *
     * $extra es opcional (compatibilidad con el alta manual de antes, que
     * solo tenía id+nombre) — cuando viene de la API de equipos de Emiliano
     * trae: cliente, estado_raw (ACTIVO/INACTIVO/SUSPENDIDO), tipo, marca,
     * modelo, capacidad_litros.
     */
    public function upsertEquipo(string $id, string $nombre, array $extra = []): void
    {
        $ahora = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        // SUSPENDIDO cuenta como inactivo para nosotros — no tiene sentido
        // seguir consultando la API por un equipo que TTM tiene suspendido.
        $estadoRaw = $extra['estado_raw'] ?? null;
        $activo = $estadoRaw === null ? 1 : ($estadoRaw === 'ACTIVO' ? 1 : 0);

        $stmt = $this->pdo->prepare('
            INSERT INTO equipos (id, nombre, activo, cliente, estado_raw, tipo, marca, modelo, capacidad_litros, diagnostico, servicio, creado_en, actualizado_en)
            VALUES (:id, :nombre, :activo, :cliente, :estado_raw, :tipo, :marca, :modelo, :capacidad_litros, :diagnostico, :servicio, :ahora, :ahora)
            ON CONFLICT(id) DO UPDATE SET
                nombre = :nombre, activo = :activo, cliente = :cliente, estado_raw = :estado_raw,
                tipo = :tipo, marca = :marca, modelo = :modelo, capacidad_litros = :capacidad_litros,
                diagnostico = :diagnostico, servicio = :servicio, actualizado_en = :ahora
        ');
        $stmt->execute([
            ':id'                => $id,
            ':nombre'            => $nombre,
            ':activo'            => $activo,
            ':cliente'           => $extra['cliente'] ?? null,
            ':estado_raw'        => $estadoRaw,
            ':tipo'              => $extra['tipo'] ?? null,
            ':marca'             => $extra['marca'] ?? null,
            ':modelo'            => $extra['modelo'] ?? null,
            ':capacidad_litros'  => $extra['capacidad_litros'] ?? null,
            ':diagnostico'       => $extra['diagnostico'] ?? null,
            ':servicio'          => $extra['servicio'] ?? null,
            ':ahora'             => $ahora,
        ]);
    }

    /**
     * Alta masiva (para sincronizar desde la API de equipos, que trae
     * 1000+ registros de una). En vez de 1000+ consultas sueltas (una por
     * equipo, carísimo en tiempo cuando la base está en Railway y cada ida
     * y vuelta por internet suma), arma pocos INSERT con muchas filas cada
     * uno (de a 200) — mucho más rápido, sobre todo en conexiones con
     * latencia (como el proxy público de Postgres desde una máquina local).
     */
    public function upsertEquiposMasivo(array $equipos): int
    {
        if (empty($equipos)) {
            return 0;
        }

        $campos = ['cliente', 'estado_raw', 'tipo', 'marca', 'modelo', 'capacidad_litros', 'diagnostico', 'servicio'];
        $ahora = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $this->pdo->beginTransaction();
        foreach (array_chunk($equipos, 200) as $tanda) {
            $filas = [];
            $valores = [];
            foreach ($tanda as $eq) {
                $estadoRaw = $eq['estado_raw'] ?? null;
                $activo = $estadoRaw === null ? 1 : ($estadoRaw === 'ACTIVO' ? 1 : 0);

                $filas[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $valores[] = (string) $eq['id'];
                $valores[] = (string) $eq['nombre'];
                $valores[] = $activo;
                foreach ($campos as $campo) {
                    $valores[] = $eq[$campo] ?? null;
                }
                $valores[] = $ahora; // creado_en
                $valores[] = $ahora; // actualizado_en
            }

            $sql = '
                INSERT INTO equipos (id, nombre, activo, cliente, estado_raw, tipo, marca, modelo, capacidad_litros, diagnostico, servicio, creado_en, actualizado_en)
                VALUES ' . implode(', ', $filas) . '
                ON CONFLICT(id) DO UPDATE SET
                    nombre = excluded.nombre, activo = excluded.activo, cliente = excluded.cliente,
                    estado_raw = excluded.estado_raw, tipo = excluded.tipo, marca = excluded.marca,
                    modelo = excluded.modelo, capacidad_litros = excluded.capacidad_litros,
                    diagnostico = excluded.diagnostico, servicio = excluded.servicio, actualizado_en = excluded.actualizado_en
            ';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($valores);
        }
        $this->pdo->commit();

        return count($equipos);
    }

    /**
     * Desactiva cualquier equipo que esté marcado activo en la base pero
     * NO haya venido en la última sincronización con la API de equipos de
     * Emiliano — típicamente, equipos que quedaron cargados del excel
     * viejo (antes de tener esta API) con otro esquema de IDs, o equipos
     * que TTM ya no reporta más. La API de equipos pasa a ser la fuente de
     * verdad: si no está ahí, no debería contar como "monitoreado".
     * No los borra — solo los marca inactivos, por las dudas.
     */
    public function desactivarEquiposNoPresentes(array $idsPresentes): int
    {
        if (empty($idsPresentes)) {
            return 0; // por seguridad: nunca desactivar todo por una sync vacía/fallida
        }

        $placeholders = implode(',', array_fill(0, count($idsPresentes), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE equipos SET activo = 0, actualizado_en = ?
            WHERE activo = 1 AND id NOT IN ({$placeholders})
        ");
        $ahora = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stmt->execute(array_merge([$ahora], $idsPresentes));
        return $stmt->rowCount();
    }

    public function listarEquipos(bool $soloActivos = true): array
    {
        $sql = 'SELECT id, nombre, activo, cliente, estado_raw, tipo, marca, modelo, capacidad_litros, diagnostico, servicio FROM equipos';
        if ($soloActivos) {
            $sql .= ' WHERE activo = 1';
        }
        $sql .= ' ORDER BY id ASC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function equipoPorId(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre, activo, cliente, estado_raw, tipo, marca, modelo, capacidad_litros, diagnostico, servicio FROM equipos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Cantidad de anomalías por equipo en un rango, en UNA sola consulta
     * agregada — con una flota de más de 1000 equipos, hacer esta cuenta
     * equipo por equipo en un loop sería carísimo.
     */
    public function contarAnomaliasPorEquipo(DateTimeInterface $desde, DateTimeInterface $hasta): array
    {
        $stmt = $this->pdo->prepare('
            SELECT a.equipo, COUNT(*) as cantidad
            FROM anomalias a
            JOIN equipos e ON e.id = a.equipo AND e.activo = 1
            WHERE a.fecha BETWEEN :desde AND :hasta
            GROUP BY a.equipo
        ');
        $stmt->execute([
            ':desde' => $desde->format(DateTimeInterface::ATOM),
            ':hasta' => $hasta->format(DateTimeInterface::ATOM),
        ]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['equipo']] = (int) $row['cantidad'];
        }
        return $out;
    }

    /**
     * Anomalías de TODA la flota (o de un equipo puntual, o de un tipo
     * puntual) en un rango — una sola consulta, para la pantalla de
     * "Anomalías" con filtros. Trae también el nombre del equipo (join
     * simple contra la tabla equipos).
     */
    // Solo equipos ACTIVOS (24/09/2026): los que TTM ya no tiene en su
    // API (o tiene suspendidos) quedan desactivados, pero sus lecturas
    // viejas siguen guardadas. Si mostraba sus anomalías, aparecían
    // "fantasmas" con nombres viejos del Excel (ej. "ONZ859 (Volvo - 370)")
    // que ni siquiera abren en el sistema de TTM.
    //
    // El límite estaba en 500 y con la flota entera y 30 días se quedaba
    // corto: los KPIs de arriba (total, litros, vehículos) salían con
    // menos de lo real porque se calculan sobre lo que devuelve esta
    // consulta. Lo subí a 5000.
    public function anomaliasFlota(DateTimeInterface $desde, DateTimeInterface $hasta, ?string $equipo = null, ?string $tipo = null, ?string $cliente = null, int $limite = 5000): array
    {
        $sql = '
            SELECT a.*, e.nombre AS equipo_nombre, e.cliente AS equipo_cliente
            FROM anomalias a
            JOIN equipos e ON e.id = a.equipo
            WHERE a.fecha BETWEEN :desde AND :hasta
              AND e.activo = 1
        ';
        $params = [
            ':desde' => $desde->format(DateTimeInterface::ATOM),
            ':hasta' => $hasta->format(DateTimeInterface::ATOM),
        ];
        if ($equipo !== null && $equipo !== '') {
            $sql .= ' AND a.equipo = :equipo';
            $params[':equipo'] = $equipo;
        }
        if ($tipo !== null && $tipo !== '') {
            $sql .= ' AND a.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($cliente !== null && $cliente !== '') {
            // Filtra por la flota/cliente completa — todos los equipos de
            // ese cliente, no uno puntual. Pedido de Emiliano: poder ver
            // cuántas anomalías tiene una flota entera, no equipo por equipo.
            $sql .= ' AND e.cliente = :cliente';
            $params[':cliente'] = $cliente;
        }
        $sql .= ' ORDER BY a.fecha DESC LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Guarda lecturas normalizadas, ignorando duplicados (equipo+fecha ya existentes). */
    public function guardarLecturas(array $lecturas): int
    {
        // INSERT OR IGNORE es sintaxis de SQLite; el equivalente en
        // Postgres es ON CONFLICT ... DO NOTHING (usando la misma
        // restricción UNIQUE(equipo, fecha) que ya está en el esquema).
        if ($this->driver === 'pgsql') {
            $sql = '
                INSERT INTO lecturas
                    (equipo, fecha, evento, lat, lng, velocidad, combustible_litros,
                     tanque1_litros, tanque2_litros, tanque3_litros, tanque4_litros,
                     variacion, odometro_km, motor_encendido, wif, altitud, temp_tanque)
                VALUES
                    (:equipo, :fecha, :evento, :lat, :lng, :velocidad, :combustible_litros,
                     :tanque1_litros, :tanque2_litros, :tanque3_litros, :tanque4_litros,
                     :variacion, :odometro_km, :motor_encendido, :wif, :altitud, :temp_tanque)
                ON CONFLICT (equipo, fecha) DO NOTHING
            ';
        } else {
            $sql = '
                INSERT OR IGNORE INTO lecturas
                    (equipo, fecha, evento, lat, lng, velocidad, combustible_litros,
                     tanque1_litros, tanque2_litros, tanque3_litros, tanque4_litros,
                     variacion, odometro_km, motor_encendido, wif, altitud, temp_tanque)
                VALUES
                    (:equipo, :fecha, :evento, :lat, :lng, :velocidad, :combustible_litros,
                     :tanque1_litros, :tanque2_litros, :tanque3_litros, :tanque4_litros,
                     :variacion, :odometro_km, :motor_encendido, :wif, :altitud, :temp_tanque)
            ';
        }
        $stmt = $this->pdo->prepare($sql);

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
                ':wif'                => isset($l['wif']) && $l['wif'] !== null ? ($l['wif'] ? 1 : 0) : null,
                ':altitud'            => $l['altitud'] ?? null,
                ':temp_tanque'        => $l['temp_tanque'] ?? null,
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
                'wif'                => isset($r['wif']) && $r['wif'] !== null ? (bool) $r['wif'] : null,
                'altitud'            => isset($r['altitud']) && $r['altitud'] !== null ? (float) $r['altitud'] : null,
                'temp_tanque'        => isset($r['temp_tanque']) && $r['temp_tanque'] !== null ? (float) $r['temp_tanque'] : null,
            ];
        }, $rows);
    }

    /**
     * Estima la capacidad del tanque a partir del PROPIO historial del
     * equipo, cuando TTM no tiene el dato cargado (que es la mayoría de
     * los casos — no vamos a depender de que lo carguen, así que el
     * sistema tiene que poder arreglárselas solo con lo que ya observó).
     *
     * Usa el percentil 95 de los niveles de combustible vistos en los
     * últimos `$diasHistorial` días, con un margen del 20% arriba — el
     * percentil (no el máximo puro) lo hace resistente a que se haya
     * colado algún valor raro en el historial antes de los fixes de hoy.
     * Devuelve null si no hay suficiente historial todavía para confiar
     * en la estimación (equipo nuevo, por ejemplo) — en ese caso, quien
     * llama cae al techo genérico.
     */
    /**
     * Para el script de reprocesar: qué equipos tienen lecturas guardadas
     * desde tal fecha. Así solo recalculo los que tienen datos.
     */
    public function equiposConLecturasDesde(DateTimeInterface $desde): array
    {
        // Solo equipos activos: no tiene sentido recalcular anomalías de
        // equipos que TTM ya no tiene o tiene suspendidos.
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT l.equipo FROM lecturas l
            JOIN equipos e ON e.id = l.equipo AND e.activo = 1
            WHERE l.fecha >= :desde ORDER BY l.equipo
        ');
        $stmt->execute([':desde' => $desde->format(DateTimeInterface::ATOM)]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'equipo');
    }

    /**
     * Borra las anomalías que salen de CALCULAR sobre las lecturas (todas
     * menos el agua que viene del diagnóstico de TTM, que no se recalcula
     * desde lecturas). Se usa antes de reprocesar con reglas nuevas.
     */
    public function borrarAnomaliasCalculadasDesde(DateTimeInterface $desde): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM anomalias
            WHERE fecha >= :desde
              AND tipo IN ('descarga_detenido', 'caida_instantanea', 'consumo_anomalo', 'ralenti', 'variacion_temperatura', 'desconexion_sensor', 'corte_alimentacion', 'carga_no_coincide')
        ");
        $stmt->execute([':desde' => $desde->format(DateTimeInterface::ATOM)]);
        return $stmt->rowCount();
    }

    public function capacidadEstimadaPorHistorial(string $equipo, int $diasHistorial = 90, int $minimoLecturas = 30): ?float
    {
        $desde = (new DateTimeImmutable())->modify("-{$diasHistorial} days")->format(DateTimeInterface::ATOM);
        $stmt = $this->pdo->prepare('
            SELECT combustible_litros FROM lecturas
            WHERE equipo = :equipo AND fecha >= :desde
              AND combustible_litros IS NOT NULL AND combustible_litros > 0
            ORDER BY combustible_litros ASC
        ');
        $stmt->execute([':equipo' => $equipo, ':desde' => $desde]);
        $valores = array_map('floatval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'combustible_litros'));

        $n = count($valores);
        if ($n < $minimoLecturas) {
            return null;
        }

        $indicePercentil95 = (int) floor(0.95 * ($n - 1));
        $percentil95 = $valores[$indicePercentil95];

        return round($percentil95 * 1.2, 1);
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

    /**
     * Para señales que no tienen un instante puntual (como diagnostico=AGUA
     * de la API de equipos, que es un estado ACTUAL, no una lectura con
     * fecha) — evita crear una anomalía nueva en cada sincronización
     * mientras el diagnóstico se mantenga igual. Solo mira si ya hay una
     * sin resolver de ese tipo para ese equipo en los últimos N días.
     */
    public function existeAnomaliaPendienteReciente(string $equipo, string $tipo, int $dias = 7): bool
    {
        $desde = (new DateTimeImmutable())->modify("-{$dias} days")->format(DateTimeInterface::ATOM);
        $stmt = $this->pdo->prepare('
            SELECT id FROM anomalias
            WHERE equipo = :equipo AND tipo = :tipo AND resuelta = 0 AND fecha >= :desde
            LIMIT 1
        ');
        $stmt->execute([':equipo' => $equipo, ':tipo' => $tipo, ':desde' => $desde]);
        return $stmt->fetch() !== false;
    }

    public function marcarAlertada(int $anomaliaId): void
    {
        $stmt = $this->pdo->prepare('UPDATE anomalias SET alertado = 1 WHERE id = :id');
        $stmt->execute([':id' => $anomaliaId]);
    }

    /**
     * Marca anomalías como resueltas — se llama cuando alguien descarga o
     * manda el PDF de un informe que las incluye. Es lo que hace bajar el
     * contador/alarma del panel: mientras no se "resuelvan" (con un PDF
     * generado), cuentan como pendientes.
     */
    public function marcarAnomaliasResueltas(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $ahora = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE anomalias SET resuelta = 1, resuelta_en = ?
            WHERE id IN ({$placeholders}) AND resuelta = 0
        ");
        $stmt->execute(array_merge([$ahora], array_map('intval', $ids)));
        return $stmt->rowCount();
    }

    /**
     * Marca como resueltas TODAS las anomalías de un equipo dentro de un
     * rango — se usa cuando se descarga el PDF de un informe (el PDF
     * incluye "las anomalías del período", no IDs puntuales elegidos a
     * mano, así que resolvemos por rango).
     */
    public function marcarResueltasPorEquipoYRango(string $equipo, DateTimeInterface $desde, DateTimeInterface $hasta): int
    {
        $ahora = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stmt = $this->pdo->prepare('
            UPDATE anomalias SET resuelta = 1, resuelta_en = :ahora
            WHERE equipo = :equipo AND fecha BETWEEN :desde AND :hasta AND resuelta = 0
        ');
        $stmt->execute([
            ':ahora'  => $ahora,
            ':equipo' => $equipo,
            ':desde'  => $desde->format(DateTimeInterface::ATOM),
            ':hasta'  => $hasta->format(DateTimeInterface::ATOM),
        ]);
        return $stmt->rowCount();
    }

    /**
     * Cantidad total de anomalías PENDIENTES (no resueltas) en toda la
     * flota — lo que muestra la alarma/badge fija en la barra superior del
     * panel. No se filtra por período: una anomalía sigue "pendiente"
     * hasta que alguien la resuelve, sin importar cuándo pasó.
     */
    public function contarAnomaliasPendientesGlobal(): int
    {
        $stmt = $this->pdo->query('
            SELECT COUNT(*) as cantidad FROM anomalias a
            JOIN equipos e ON e.id = a.equipo AND e.activo = 1
            WHERE a.resuelta = 0
        ');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cantidad'] ?? 0);
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
