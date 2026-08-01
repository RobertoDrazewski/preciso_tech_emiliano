<?php

/**
 * Confirmado con datos reales de la API + aclaraciones de Emiliano
 * (23/07/2026 y 27/07/2026). Respuesta real:
 * {"success": true, "total": N, "data": [ {...}, ... ]}
 *
 * ⚠️ IMPORTANTE — la API separa las lecturas en dos tipos de evento, cada uno
 * con distintos campos "reales" y el resto en cero (no faltantes, CERO):
 *
 *   - evento = "DATA"   → combustible_total/niveles son reales. odometro
 *                         viene siempre en 0 (no confiar en él acá).
 *   - evento = "TIEMPO" → odometro es real. combustible_total/niveles vienen
 *                         siempre en 0 (no confiar en ellos acá).
 *
 * ⚠️ NO HAY CAPACIDAD DE TANQUE (confirmado por Emiliano 27/07/2026): todo
 * se maneja en litros absolutos, nunca en % de un tanque. combustible_total
 * ("nivel total") es el campo que hay que usar para los cálculos.
 *
 * Los campos tanque1-4 (nivel1-4 en el JSON) son lecturas de cada tanque
 * individual, para vehículos con más de un tanque. Regla de Emiliano:
 *   - 0        → tanque recién desconectado, IGNORAR esa lectura.
 *   - 65535    → tanque no está presente en este vehículo, IGNORAR.
 *   - cualquier otro valor → lectura válida de ese tanque.
 * combustible_total ya viene agregado (sumado), así que para los cálculos
 * de anomalías se usa ÚNICAMENTE combustible_total — los tanque1-4 son solo
 * para mostrar el detalle por tanque en el informe, no para las cuentas.
 *
 * El campo 'variacion' (antes veíamos siempre 0 en la muestra chica) según
 * Emiliano SÍ trae un valor cuando hay una carga o una descarga de
 * combustible, y viene en 0 el resto del tiempo — es una señal que el
 * dispositivo ya calcula solo. Todavía no vimos un valor real distinto de
 * cero para confirmar el signo/unidad exactos (positivo = carga, negativo =
 * descarga, es la hipótesis más razonable) — en cuanto aparezca un caso real
 * en los logs, ajustar AnomalyDetector.php para usarlo como señal extra de
 * confirmación (hoy se guarda y se muestra, pero todavía no pesa en la
 * decisión de anomalía).
 */

return [

    'root_key' => 'data',

    'fields' => [
        'equipo' => [
            'raw' => ['equipo'],
        ],
        'fecha' => [
            'raw' => ['fecha_reporte'],
            'format' => 'Y-m-d H:i:s',
        ],
        'evento' => [
            'raw' => ['evento'],
        ],
        'lat' => [
            'raw' => ['latitud'],
        ],
        'lng' => [
            'raw' => ['longitud'],
        ],
        'velocidad' => [
            'raw' => ['velocidad'],
        ],
        'combustible_litros' => [
            // "nivel total" — el campo autoritativo para todos los cálculos.
            'raw' => ['combustible_total'],
        ],
        'tanque1_litros' => [
            'raw' => ['nivel1'],
        ],
        'tanque2_litros' => [
            'raw' => ['nivel2'],
        ],
        'tanque3_litros' => [
            'raw' => ['nivel3'],
        ],
        'tanque4_litros' => [
            'raw' => ['nivel4'],
        ],
        'variacion' => [
            // Señal del propio dispositivo: !=0 cuando detectó una carga o
            // descarga. Se guarda y se muestra; ver nota arriba sobre
            // integrarlo a la lógica de anomalías cuando tengamos un caso real.
            'raw' => ['variacion'],
        ],
        'odometro_km' => [
            'raw' => ['odometro'],
        ],
        'motor_encendido' => [
            // No hay campo de ignición real en esta API. 'movimiento' (0/1)
            // es el proxy más cercano disponible: 1 = en movimiento,
            // 0 = detenido. Limitación conocida: un vehículo detenido con el
            // motor en marcha (ralentí) también da movimiento=0.
            'raw' => ['ignicion', 'motor', 'engine_on', 'acc', 'movimiento'],
        ],
    ],
];
