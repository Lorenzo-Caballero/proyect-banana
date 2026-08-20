<?php
/**
 * CRUD de jugadores.
 *
 *   GET    jugadores_crud.php                 -> listar (paginado)
 *          &pagina=1&por_pagina=20
 *          &buscar=texto        (usuario, correo, nombre, apellido)
 *          &creado=0|1          (filtra por alta en el panel)
 *   GET    jugadores_crud.php?id=5            -> uno solo
 *   POST   jugadores_crud.php                 -> crear
 *   PUT    jugadores_crud.php?id=5            -> actualizar (campos sueltos)
 *   DELETE jugadores_crud.php?id=5            -> borrar
 *
 * Todo requiere header:  X-API-Key: <clave>
 *
 * Nunca devuelve `contrasena` ni `panel_password`: son secretos, no datos.
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Sin clave configurada NO se abre: una clave por defecto seria publica,
// porque esta escrita en el codigo que subiste al server.
exigir_api_key();

/** Columnas que si se pueden mostrar. */
const CAMPOS_PUBLICOS = 'id, nombre_usuario, correo, nombre, apellido, creado,
                         panel_estado, panel_intentos, panel_mensaje,
                         panel_creado_en, creado_en';

function responder(array $cuerpo, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function cuerpoJson(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : $_POST;
}

/** Valida los campos que llegan. $parcial = true para PUT (todo opcional). */
function validar(array $d, bool $parcial): array {
    $e = [];
    $hay = fn(string $k) => array_key_exists($k, $d);

    if (!$parcial || $hay('usuario')) {
        $u = trim((string)($d['usuario'] ?? ''));
        if ($u === '')                                  $e['usuario'] = 'Escribe el usuario.';
        elseif (!preg_match('/^[\w.\-]{3,50}$/u', $u))  $e['usuario'] = 'Entre 3 y 50: letras, numeros, punto, guion y guion bajo.';
    }
    if (!$parcial || $hay('password')) {
        $p = (string)($d['password'] ?? '');
        if (strlen($p) < 6)                             $e['password'] = 'Minimo 6 caracteres.';
    }
    if (!$parcial || $hay('correo')) {
        $c = trim((string)($d['correo'] ?? ''));
        if (!filter_var($c, FILTER_VALIDATE_EMAIL))     $e['correo'] = 'Correo invalido.';
    }
    foreach (['nombre', 'apellido'] as $campo) {
        if (!$parcial || $hay($campo)) {
            $v = trim((string)($d[$campo] ?? ''));
            if ($v === '')                              $e[$campo] = 'No puede quedar vacio.';
            elseif (mb_strlen($v) > 80)                 $e[$campo] = 'Maximo 80 caracteres.';
        }
    }
    return $e;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---------------------------------------------------------------------------
// READ
// ---------------------------------------------------------------------------
if ($metodo === 'GET') {

    if ($id) {
        $st = $pdo->prepare('SELECT ' . CAMPOS_PUBLICOS . ' FROM jugadores WHERE id = ?');
        $st->execute([$id]);
        $fila = $st->fetch();
        if (!$fila) responder(['ok' => false, 'error' => 'No existe'], 404);
        responder(['ok' => true, 'jugador' => $fila]);
    }

    $pagina     = max((int)($_GET['pagina'] ?? 1), 1);
    $porPagina  = min(max((int)($_GET['por_pagina'] ?? 20), 1), 100);
    $offset     = ($pagina - 1) * $porPagina;

    $where  = [];
    $params = [];

    if (($buscar = trim((string)($_GET['buscar'] ?? ''))) !== '') {
        $where[] = '(nombre_usuario LIKE ? OR correo LIKE ? OR nombre LIKE ? OR apellido LIKE ?)';
        $like    = '%' . $buscar . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (isset($_GET['creado']) && $_GET['creado'] !== '') {
        $where[]  = 'creado = ?';
        $params[] = (int)$_GET['creado'] ? 1 : 0;
    }

    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM jugadores $sqlWhere");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    // pagina/por_pagina ya son enteros validados, no vienen crudos del cliente
    $st = $pdo->prepare(
        'SELECT ' . CAMPOS_PUBLICOS . " FROM jugadores $sqlWhere
          ORDER BY id DESC LIMIT $porPagina OFFSET $offset"
    );
    $st->execute($params);

    responder([
        'ok'         => true,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $porPagina,
        'paginas'    => (int)ceil($total / $porPagina),
        'datos'      => $st->fetchAll(),
    ]);
}

// ---------------------------------------------------------------------------
// CREATE
// ---------------------------------------------------------------------------
if ($metodo === 'POST') {
    $d = cuerpoJson();
    if ($e = validar($d, false)) responder(['ok' => false, 'errores' => $e], 422);

    try {
        $st = $pdo->prepare(
            'INSERT INTO jugadores (nombre_usuario, contrasena, correo, nombre, apellido, panel_password)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            trim($d['usuario']),
            password_hash((string)$d['password'], PASSWORD_DEFAULT),
            trim($d['correo']),
            trim($d['nombre']),
            trim($d['apellido']),
            (string)$d['password'],   // en claro para el bot; se borra al crearse
        ]);
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) == 1062) {
            responder(['ok' => false, 'error' => 'Ese usuario o correo ya existe'], 409);
        }
        error_log('crud create: ' . $ex->getMessage());
        responder(['ok' => false, 'error' => 'Error al guardar'], 500);
    }

    $nuevo = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT ' . CAMPOS_PUBLICOS . ' FROM jugadores WHERE id = ?');
    $st->execute([$nuevo]);
    responder(['ok' => true, 'jugador' => $st->fetch()], 201);
}

// ---------------------------------------------------------------------------
// UPDATE
// ---------------------------------------------------------------------------
if ($metodo === 'PUT') {
    if (!$id) responder(['ok' => false, 'error' => 'Falta el id'], 400);

    $d = cuerpoJson();
    if ($e = validar($d, true)) responder(['ok' => false, 'errores' => $e], 422);

    $mapa = [
        'usuario'  => 'nombre_usuario',
        'correo'   => 'correo',
        'nombre'   => 'nombre',
        'apellido' => 'apellido',
    ];

    $sets = [];
    $vals = [];
    foreach ($mapa as $entrada => $columna) {
        if (array_key_exists($entrada, $d)) {
            $sets[] = "$columna = ?";
            $vals[] = trim((string)$d[$entrada]);
        }
    }

    if (array_key_exists('password', $d)) {
        // Cambiar la clave obliga a rehacer el alta: la del panel quedo vieja.
        $sets[] = 'contrasena = ?';
        $vals[] = password_hash((string)$d['password'], PASSWORD_DEFAULT);
        $sets[] = 'panel_password = ?';
        $vals[] = (string)$d['password'];
    }

    if (array_key_exists('creado', $d)) {
        $sets[] = 'creado = ?';
        $vals[] = (int)$d['creado'] ? 1 : 0;
        // Reencolar a mano: util para forzar un reintento desde el panel.
        if (!(int)$d['creado']) {
            $sets[] = "panel_estado = 'pendiente'";
            $sets[] = 'panel_intentos = 0';
            $sets[] = 'panel_mensaje = NULL';
        }
    }

    if (!$sets) responder(['ok' => false, 'error' => 'Nada para actualizar'], 400);

    $vals[] = $id;
    try {
        $st = $pdo->prepare('UPDATE jugadores SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->execute($vals);
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) == 1062) {
            responder(['ok' => false, 'error' => 'Ese usuario o correo ya existe'], 409);
        }
        error_log('crud update: ' . $ex->getMessage());
        responder(['ok' => false, 'error' => 'Error al actualizar'], 500);
    }

    $st = $pdo->prepare('SELECT ' . CAMPOS_PUBLICOS . ' FROM jugadores WHERE id = ?');
    $st->execute([$id]);
    $fila = $st->fetch();
    if (!$fila) responder(['ok' => false, 'error' => 'No existe'], 404);
    responder(['ok' => true, 'jugador' => $fila]);
}

// ---------------------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------------------
if ($metodo === 'DELETE') {
    if (!$id) responder(['ok' => false, 'error' => 'Falta el id'], 400);

    $st = $pdo->prepare('DELETE FROM jugadores WHERE id = ?');
    $st->execute([$id]);

    if (!$st->rowCount()) responder(['ok' => false, 'error' => 'No existe'], 404);
    // Ojo: borra de TU base, no del panel de agentes. Alla sigue existiendo.
    responder(['ok' => true, 'borrado' => $id]);
}

responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
