<?php
/**
 * Config dinámica del frontend, servida como si fuera un .js.
 *
 * En local no hace falta tocar nada: usa http://localhost:8000/api por
 * default. En Railway (o cualquier otro hosting), seteá la variable de
 * entorno API_BASE apuntando a la URL pública del backend — así no hay que
 * editar ningún archivo a mano antes de desplegar.
 */
header('Content-Type: application/javascript; charset=utf-8');

$apiBase = getenv('API_BASE') ?: 'http://localhost:8000/api';
?>
window.PRECISO_CONFIG = {
  API_BASE: '<?= addslashes($apiBase) ?>',
};
