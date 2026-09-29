<?php

/**
 * Umbrales que se pueden cambiar desde la pantalla (botón de ajustes al
 * lado de cada tipo de anomalía). Pedido de Emiliano, 29/09/2026.
 *
 * Cada parámetro: la clave (la misma que en config.php), un nombre y una
 * ayuda en criollo para el operador, la unidad y un rango permitido (para
 * que nadie ponga un número que rompa la detección). El valor por defecto
 * sale de config.php; lo que se guarde desde la pantalla lo pisa.
 *
 * Ojo: el cambio vale para lo que se detecte de ahí en adelante. Para
 * recalcular lo anterior hay que correr scripts/reprocesar_anomalias.php.
 */
return [
    'descarga_detenido' => [
        ['clave' => 'stopped_drop_liters_threshold', 'nombre' => 'Litros mínimos', 'unidad' => 'L', 'min' => 1, 'max' => 500,
         'ayuda' => 'Cuántos litros tiene que perder parado para avisar. Por debajo es ruido del sensor.'],
        ['clave' => 'detenido_distancia_max_metros', 'nombre' => 'Distancia máxima para "detenido"', 'unidad' => 'm', 'min' => 10, 'max' => 2000,
         'ayuda' => 'Si se movió más que esto entre dos lecturas, no estaba detenido.'],
    ],
    'caida_instantanea' => [
        ['clave' => 'min_drop_liters_instant', 'nombre' => 'Litros mínimos', 'unidad' => 'L', 'min' => 1, 'max' => 1000,
         'ayuda' => 'Cuántos litros tienen que caer de golpe para avisar.'],
        ['clave' => 'min_drop_window_minutes', 'nombre' => 'En cuántos minutos', 'unidad' => 'min', 'min' => 1, 'max' => 120,
         'ayuda' => 'La caída tiene que pasar en este tiempo o menos.'],
    ],
    'consumo_anomalo' => [
        ['clave' => 'z_threshold', 'nombre' => 'Qué tan fuera de lo normal', 'unidad' => 'desvíos', 'min' => 1.5, 'max' => 10,
         'ayuda' => 'Cuántos desvíos estándar por encima del consumo habitual de ESE vehículo. Más alto = menos avisos.'],
        ['clave' => 'consumo_anomalo_litros_minimos', 'nombre' => 'Litros mínimos', 'unidad' => 'L', 'min' => 1, 'max' => 500,
         'ayuda' => 'Aunque sea raro, por debajo de esto no avisa.'],
        ['clave' => 'min_history_points', 'nombre' => 'Historial mínimo', 'unidad' => 'caídas', 'min' => 5, 'max' => 500,
         'ayuda' => 'Cuántas caídas del vehículo hacen falta antes de poder decir qué es "normal" para él.'],
    ],
    'agua_combustible' => [],
    'ralenti' => [
        ['clave' => 'ralenti_minutos_minimos', 'nombre' => 'Minutos mínimos', 'unidad' => 'min', 'min' => 5, 'max' => 600,
         'ayuda' => 'Cuánto tiempo con el motor prendido y parado para avisar.'],
        ['clave' => 'ralenti_litros_minimos', 'nombre' => 'Litros mínimos', 'unidad' => 'L', 'min' => 0.5, 'max' => 200,
         'ayuda' => 'Cuánto tiene que haber consumido en ese tiempo.'],
    ],
    'variacion_temperatura' => [
        ['clave' => 'temp_correlacion_minima', 'nombre' => 'Correlación mínima', 'unidad' => '0 a 1', 'min' => 0.5, 'max' => 1,
         'ayuda' => 'Qué tan pegadas tienen que ir la curva de temperatura y la de nivel (1 = idénticas).'],
        ['clave' => 'temp_factor_exceso', 'nombre' => 'Veces por encima de la dilatación', 'unidad' => 'x', 'min' => 1, 'max' => 20,
         'ayuda' => 'Cuántas veces más de lo que explica la dilatación del gasoil tiene que variar el nivel.'],
        ['clave' => 'temp_variacion_minima_litros', 'nombre' => 'Litros mínimos', 'unidad' => 'L', 'min' => 1, 'max' => 200,
         'ayuda' => 'Por debajo de esta variación no avisa.'],
    ],
    'desconexion_sensor' => [
        ['clave' => 'tasa_maxima_fisica_lpm', 'nombre' => 'Velocidad máxima real', 'unidad' => 'L/min', 'min' => 20, 'max' => 1000,
         'ayuda' => 'Una caída más rápida que esto no es combustible saliendo: es el sensor que se desconectó.'],
    ],
    'corte_alimentacion' => [
        ['clave' => 'corte_litros_sospechosos', 'nombre' => 'Litros faltantes para avisar', 'unidad' => 'L', 'min' => 1, 'max' => 500,
         'ayuda' => 'Si al volver la alimentación faltan estos litros o más, es posible robo.'],
        ['clave' => 'corte_reportar_sin_perdida', 'nombre' => 'Avisar también cortes sin pérdida', 'unidad' => '0 = no, 1 = sí', 'min' => 0, 'max' => 1,
         'ayuda' => 'En 1 avisa todos los cortes (son muchos: el corta-corriente de cada día).'],
    ],
    'carga_no_coincide' => [
        ['clave' => 'carga_diferencia_min_litros', 'nombre' => 'Diferencia mínima', 'unidad' => 'L', 'min' => 1, 'max' => 500,
         'ayuda' => 'Cuántos litros de diferencia entre lo informado y lo medido para avisar.'],
        ['clave' => 'carga_diferencia_min_porcentaje', 'nombre' => 'Diferencia mínima', 'unidad' => '0 a 1 (0,15 = 15%)', 'min' => 0.01, 'max' => 1,
         'ayuda' => 'Además, qué porcentaje de la carga tiene que ser la diferencia.'],
        ['clave' => 'carga_tolerancia_meseta_litros', 'nombre' => 'Tolerancia de estabilidad', 'unidad' => 'L', 'min' => 0.5, 'max' => 20,
         'ayuda' => 'Cuánto puede oscilar el nivel y seguir considerándose "estable" antes y después de la carga.'],
    ],
];
