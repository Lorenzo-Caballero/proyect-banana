<?php
/**
 * tenant_info.php — ¿Este dominio (+ slug de path) es un cliente registrado?
 *
 * Existe para UNA cosa: que el widget, inyectado dentro de la plataforma,
 * pueda VALIDAR el slug que capturó de la URL de entrada antes de adoptarlo
 * (ver landing/widget.js, bloque path-tenant). db.php hace todo el trabajo:
 * si el host+slug no es un cliente activo, muere ahí con 404
 * "Dominio no registrado" y este archivo ni se ejecuta; si llegamos acá, el
 * tenant existe y su base abre.
 *
 * Público a propósito (lo llama el navegador del jugador antes de tener
 * cuenta) y sin datos sensibles: solo confirma la existencia y devuelve el
 * slug/dominio, que el que pregunta ya tenía en la URL.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok'      => true,
    'slug'    => (string)($GLOBALS['TENANT_SLUG'] ?? ''),
    'dominio' => (string)($GLOBALS['TENANT_HOST'] ?? ''),
], JSON_UNESCAPED_UNICODE);
