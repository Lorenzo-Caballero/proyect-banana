<?php
/**
 * crm.php — Backend del CRM de conversaciones.
 *
 * Exige login de operador (exigir_operador()/exigir_admin() de crm_auth.php).
 * Multi-agente: rol 'admin' gestiona agentes (agentes_listar/agente_crear/
 * agente_estado); rol 'agente' solo atiende chats.
 *
 * GET  ?accion=conversaciones&q=&estado=todas|abierta|pendiente|cerrada|archivadas
 *          &inactivos=0|15|30|90&inactivos_tipo=carga|chat
 *          &orden=reciente|antiguo|inactivos
 *        -> { ok, items:[{id,usuario,session_id,estado,preview,no_leidos,actualizada_en}], resumen }
 * GET  ?accion=conversacion&id=N
 *        -> { ok, conversacion, mensajes:[...], usuario:{...}|null, movimientos:[...] }
 *
 * POST { accion:"nota",         id, notas }
 * POST { accion:"estado",       id, estado }
 * POST { accion:"archivar",     id|ids[], archivar? }  // sacar/devolver a la bandeja
 * POST { accion:"eliminar",     id|ids[] }             // borra el hilo — SOLO ADMIN
 * POST { accion:"difusion_seleccion", ids[], texto }   // mensaje de agente a varios chats
 * GET  ?accion=plantillas
 * POST { accion:"plantilla_guardar", id?, comando, texto, atajo? }
 * POST { accion:"plantilla_borrar",  id }
 * POST { accion:"cargar_fichas",usuario, monto, motivo, conversacion_id? }
 * POST { accion:"cargar_bono",  usuario, monto, motivo, conversacion_id? }
 * POST { accion:"notificar",    usuario|todos|filtro:{modo,dias?}, titulo, cuerpo, tipo? }
 * GET  ?accion=notif_historial&usuario=&desde=&hasta=&tipo=&pagina=
 * GET  ?accion=notif_alcance_inactivos&dias=N
 * GET  ?accion=notif_presets_listar
 * POST { accion:"notif_preset_guardar", nombre, filtro }
 * POST { accion:"notif_preset_borrar",  id }
 * GET  ?accion=bonos_listar&usuario=&estado=
 * POST { accion:"bono_crear",  usuario, tipo:fichas|pct|giro, valor }
 * POST { accion:"bono_editar", id, tipo, valor }
 * POST { accion:"bono_borrar", id }
 */

declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/crm_lib.php';
require __DIR__ . '/crm_auth.php';
require __DIR__ . '/notificaciones_lib.php';
require __DIR__ . '/crm_notificaciones.php';
require_once __DIR__ . '/config_crm.php';
// Telegram: opcional. Sin el archivo, la accion tg_probar avisa y el resto del
// CRM sigue funcionando igual.
$tgLibCrm = __DIR__ . '/telegram_lib.php';
if (is_file($tgLibCrm)) { require_once $tgLibCrm; }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// El CRM ahora exige login de operador (multi-agente): cada acción sabe QUÉ
// agente la hizo. exigir_operador() corta con 401 (sin sesión) o 403 (CSRF
// inválido en POST) y el frontend ya reacciona mostrando el login.
// Se sirve del mismo origen que el CRM (no cross-origin), así la cookie de
// sesión viaja; por eso se quitó el 'Access-Control-Allow-Origin: *' que
// impedía mandar credenciales.
$operador = exigir_operador();

function salir($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Trae la ficha del usuario desde `usuarios`: saldo (real, de ganamos),
 *  bono pendiente (prometido, aún no acreditado) y flags.
 *
 *  OJO SALDO vs FICHAS: `coins` ("fichas") es un contador PROPIO que lleva
 *  el CRM (cargas manuales + recargas acreditadas) -- el sync de ganamos NO
 *  trae ningún campo de fichas, solo `balance` (dinero real). Mostrar los
 *  dos como si fueran independientes confundía: acá solo se expone `saldo`
 *  (`usuarios.balance`), que es el único dato real sincronizado.
 *
 *  OJO BONO: `usuarios.bonus` es un acumulado HISTÓRICO que solo suma
 *  (ruleta + cargas manuales + bonos prometidos ya aplicados) y nunca se
 *  resta -- no refleja "lo que el jugador tiene disponible ahora". Lo que
 *  sí es accionable es `bonos_pendientes` con estado='pendiente': bonos
 *  YA PROMETIDOS por notificación que todavía esperan la próxima recarga
 *  del jugador para hacerse efectivos (ver crm_notificaciones.php). Eso es
 *  lo que se expone como `bono_pendiente`. */
function ficha_usuario(PDO $pdo, string $usuario): ?array
{
    /* `saldo_visto_en` es de la migracion 68 y puede no estar todavia: se pide
       en un SELECT aparte para que una base sin migrar siga devolviendo la
       ficha entera en vez de 500. */
    $st = $pdo->prepare(
        "SELECT id AS ganamos_id, username AS nombre_usuario,
                COALESCE(balance,0) AS saldo,
                COALESCE(bonus,0)   AS bonus,
                COALESCE(total_deposits,0) AS total_deposits,
                role, is_banned, tiene_app, notificaciones,
                creation_date, ultima_actividad
         FROM usuarios WHERE username = ? LIMIT 1"
    );
    $st->execute([$usuario]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }

    /* HACE CUANTO QUE ESE SALDO ES VERDAD. El numero sale de un espejo, y entre
       una lectura y la siguiente el jugador apuesta: mostrarlo sin la edad hacia
       que un saldo de hace horas se leyera igual que uno de hace un segundo, y
       sobre eso se decide cuanto pagarle en un retiro.
       Se manda en segundos y el texto lo arma el front, que es el que sabe
       cuando lo esta pintando. null = nunca lo leimos (o falta la migracion). */
    /* EL SALDO QUE EL JUGADOR ESTA VIENDO AHORA (`balance_web`, migracion 17).
       Lo reporta el widget desde el navegador del propio jugador, leyendolo del
       juego en vivo, y solo cuando cambia. Existe desde hace meses y NO LO
       LEIA NADIE.
       Es GRATIS: no cuesta una sola request al panel. Y suele ser mucho mas
       fresco que el espejo, que se lee cada 5 minutos.
       PERO NO AUTORIZA PLATA, y esa linea no se cruza: `saldo_reportar.php` es
       un endpoint PUBLICO y sin sesion -- un jugador puede mandar el numero que
       quiera. Sirve para MIRAR (y sobre todo para notar una diferencia), nunca
       para decidir cuanto pagarle. Lo que decide sigue siendo `balance`. */
    $r['saldo_web'] = null;
    $r['saldo_web_hace'] = null;
    try {
        $w = $pdo->prepare(
            "SELECT balance_web, TIMESTAMPDIFF(SECOND, balance_web_en, NOW()) hace
               FROM usuarios WHERE username = ? LIMIT 1"
        );
        $w->execute([$usuario]);
        if ($f = $w->fetch(PDO::FETCH_ASSOC)) {
            if ($f['balance_web'] !== null && $f['hace'] !== null) {
                $r['saldo_web']      = (float)$f['balance_web'];
                $r['saldo_web_hace'] = max(0, (int)$f['hace']);
            }
        }
    } catch (Throwable $e) {
        // Sin la migracion 17 no hay columna: la ficha sale igual.
    }

    $r['saldo_visto_hace'] = null;
    try {
        $v = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, saldo_visto_en, NOW())
               FROM usuarios WHERE username = ? LIMIT 1"
        );
        $v->execute([$usuario]);
        $seg = $v->fetchColumn();
        if ($seg !== null && $seg !== false) { $r['saldo_visto_hace'] = max(0, (int)$seg); }
    } catch (Throwable $e) {
        // Sin la migracion 68 no hay columna: la ficha sale igual, sin la edad.
    }

    // El contador de bonos SIN depositar (usuarios.bonus). Antes la ficha no
    // lo mostraba en ningun lado: el agente cargaba un bono, el numero caia
    // aca, y en pantalla no cambiaba NADA -- "el bono no funciona".
    $r['bonus']          = (int)$r['bonus'];
    $r['saldo']          = (float)$r['saldo'];
    $r['total_deposits'] = (float)$r['total_deposits'];
    $r['is_banned']      = (bool)$r['is_banned'];
    $r['tiene_app']      = (bool)($r['tiene_app'] ?? false);
    $r['notificaciones'] = (bool)($r['notificaciones'] ?? false);
    $r['registrado_sitio'] = true;
    $r['bono_pendiente'] = bono_pendiente_total($pdo, $usuario);

    /* LOS PEDIDOS DE RETIRO QUE YA TIENE ABIERTOS, de los dos lados.
       Son dos colas distintas para la misma plata: la nuestra
       (`acciones_saldo`, lo que pide por el chat o carga el operador) y la del
       panel (`retiros_panel`, el boton de retirar de adentro del juego). El
       modal de "Retirar" no las veia, asi que el operador podia abrir un
       segundo pedido sin enterarse de que ya habia uno.
       Paso el 15/09/2026: 4.000 pedidos en el juego y 4.280 cargados desde
       acá; le transfirio 4.280 al banco y despues resolvio en el panel el de
       4.000 -- quedaron 280 fichas adentro y dos pedidos contando historias
       distintas sobre la misma plata. */
    $r['retiros_abiertos'] = [];
    try {
        /* VA EL `id`, y es lo que permite ACTUAR sobre el pedido y no solo
           mirarlo. El aviso del modal existia desde el 15/09 pero era un texto:
           el operador leia "ya tiene un pedido abierto", igual retiraba a mano,
           y el pedido quedaba vivo para que OTRO operador lo aprobara despues.
           Avisarle al primero no protege del segundo. Con el id, el retiro
           manual puede cancelarlo en el mismo acto (ver `cancelar` en
           retirar_saldo). */
        $sr = $pdo->prepare(
            "SELECT id, monto, estado FROM acciones_saldo
              WHERE usuario = ? AND tipo = 'retirar'
                AND estado IN ('pendiente','procesando','revisar','error')
              ORDER BY creada_en DESC LIMIT 5"
        );
        $sr->execute([$usuario]);
        foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $f) {
            /* `procesando` ya lo tiene el worker: cancelarlo desde aca seria
               cerrar en la base algo que puede estar ejecutandose en el panel
               en este mismo segundo. Se muestra, no se ofrece cancelar. */
            $r['retiros_abiertos'][] = ['id' => (int)$f['id'],
                                        'monto' => (float)$f['monto'],
                                        'estado' => (string)$f['estado'],
                                        'del_juego' => false,
                                        'cancelable' => (string)$f['estado'] !== 'procesando'];
        }
    } catch (Throwable $e) {
        error_log('ficha_usuario/retiros: ' . $e->getMessage());
    }
    try {
        $sp = $pdo->prepare(
            "SELECT monto FROM retiros_panel
              WHERE username = ? AND estado = 'abierto'
              ORDER BY primera_vez DESC LIMIT 5"
        );
        $sp->execute([$usuario]);
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $f) {
            /* Los del juego NO se pueden cancelar desde aca: viven del lado
               de ganamos y se resuelven en SU panel. Se avisan igual --son la
               mitad del riesgo de pagar dos veces-- pero el operador tiene que
               ir a cerrarlos alla. */
            $r['retiros_abiertos'][] = ['id' => 0,
                                        'monto' => (float)$f['monto'],
                                        'estado' => 'pendiente',
                                        'del_juego' => true,
                                        'cancelable' => false];
        }
    } catch (Throwable $e) {
        // Sin la migracion 64 no hay espejo del juego: se avisa lo que se pueda.
    }

    /* ¿ESTA BLOQUEADO, Y HAY OTRAS CUENTAS QUE SON LA MISMA PERSONA?
       Nahuel descubrio a mano que holasofito763, holajuan969 y holaleiva89
       eran uno solo. Los datos para verlo estaban hace semanas --la cuenta
       bancaria desde la que pagan, el celular, la IP del alta-- y nadie los
       cruzaba. Ahora sale en la ficha, que es donde el operador ya esta
       mirando cuando decide cargarle o no.

       `bloqueado` es NUESTRA columna (migracion 69) y no `is_banned`, que es
       el espejo del baneo de la plataforma y lo pisa el sync cada 5 minutos.

       Best-effort entero: un vinculo que no se pudo calcular no puede impedir
       que se abra la ficha de un jugador. */
    $r['bloqueado'] = false;
    $r['bloqueo']   = null;
    $r['vinculos']  = [];
    try {
        if (is_file(__DIR__ . '/vinculos_lib.php')) {
            require_once __DIR__ . '/vinculos_lib.php';
        }
        if (function_exists('vin_relacionados')) {
            $qb = $pdo->prepare(
                "SELECT bloqueado, bloqueado_en, bloqueado_por, bloqueado_motivo
                   FROM usuarios WHERE username = ? LIMIT 1"
            );
            $qb->execute([$usuario]);
            if ($b = $qb->fetch(PDO::FETCH_ASSOC)) {
                $r['bloqueado'] = (bool)$b['bloqueado'];
                if ($r['bloqueado']) {
                    $r['bloqueo'] = [
                        'desde'    => $b['bloqueado_en'],
                        'por'      => $b['bloqueado_por'],
                        'motivo'   => $b['bloqueado_motivo'],
                    ];
                }
            }
            $r['vinculos'] = vin_relacionados($pdo, $usuario);

            /* LA HUELLA, EN CRUDO. Los vínculos dicen CON QUIÉN comparte; esto
               dice CON QUÉ -- desde qué billetera paga y desde qué teléfono
               entra. Pedido de Nahuel (16/09/2026): "podés agregar info de
               fingerprint, como teléfono, en el apartado de detalles del
               usuario cuando abrimos una conversación".

               Sirve para lo que el aviso no alcanza: cuando el jugador dice
               "yo tengo una sola cuenta", el operador necesita el dato concreto
               para ponérselo enfrente. Pasó tal cual esa madrugada.

               `huellas_pagador` se aprende del mail del banco cuando se acredita
               una recarga, así que es dato del BANCO, no declarado: medido el
               16/09, el 100% de los pagos trae titular, CUIT, CBU y número de
               operación. */
            $r['huella'] = ['pago' => [], 'celulares' => []];
            try {
                $qh = $pdo->prepare(
                    "SELECT cuit, cbu, nombre, usos, ultima_vez
                       FROM huellas_pagador WHERE usuario = ?
                      ORDER BY ultima_vez DESC LIMIT 5"
                );
                $qh->execute([$usuario]);
                foreach ($qh as $h) {
                    $r['huella']['pago'][] = [
                        'titular' => (string)($h['nombre'] ?? ''),
                        'cuit'    => (string)($h['cuit'] ?? ''),
                        'cbu'     => (string)($h['cbu'] ?? ''),
                        'usos'    => (int)$h['usos'],
                        'ultima'  => $h['ultima_vez'],
                    ];
                }
            } catch (Throwable $e) { /* sin huellas: la ficha va igual */ }

            try {
                /* El modelo del teléfono sale de `dispositivos` (lo manda el
                   APK al registrarse) y el historial de qué cuentas pasaron por
                   él, de `dispositivos_usuarios` (migración 69). Se juntan acá
                   porque para el operador es un solo dato: "entra desde este
                   aparato, y por ese aparato pasaron estas otras cuentas". */
                $qd = $pdo->prepare(
                    "SELECT du.device_id, du.usos, du.ultima_vez,
                            d.modelo, d.plataforma,
                            (SELECT COUNT(DISTINCT o.usuario) FROM dispositivos_usuarios o
                              WHERE o.device_id = du.device_id) AS cuentas
                       FROM dispositivos_usuarios du
                       LEFT JOIN dispositivos d ON d.device_id = du.device_id
                      WHERE du.usuario = ?
                      ORDER BY du.ultima_vez DESC LIMIT 5"
                );
                $qd->execute([$usuario]);
                foreach ($qd as $d) {
                    $r['huella']['celulares'][] = [
                        'modelo'     => (string)($d['modelo'] ?? ''),
                        'plataforma' => (string)($d['plataforma'] ?? ''),
                        'usos'       => (int)$d['usos'],
                        'cuentas'    => (int)$d['cuentas'],
                        'ultima'     => $d['ultima_vez'],
                        /* El id entero no sirve para nada en pantalla y es
                           ruido; los últimos 6 alcanzan para distinguir dos
                           aparatos y para buscarlo si hace falta. */
                        'ref'        => substr((string)$d['device_id'], -6),
                    ];
                }
            } catch (Throwable $e) { /* sin migración 69: sin celulares */ }
        }
    } catch (Throwable $e) {
        error_log('ficha_usuario/vinculos: ' . $e->getMessage());
    }

    return $r;
}

/** Suma de bonos_pendientes (fichas + pct, estado='pendiente') de un
 *  usuario. El de tipo 'pct' todavía no tiene monto fijo (depende de
 *  cuánto cargue), así que se cuenta como cantidad de promesas, no como
 *  pesos -- ver bono_pendiente_desglose() para el detalle completo.
 *  Nunca lanza: si falta la migración 33, la ficha sigue andando sin este
 *  dato (vuelve 0). */
function bono_pendiente_total(PDO $pdo, string $usuario): array
{
    try {
        $st = $pdo->prepare(
            "SELECT tipo, COUNT(*) AS cant, COALESCE(SUM(valor),0) AS suma
               FROM bonos_pendientes
              WHERE usuario = ? AND estado = 'pendiente'
              GROUP BY tipo"
        );
        $st->execute([$usuario]);
        $out = ['fichas' => 0, 'pct_cantidad' => 0, 'giro_cantidad' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['tipo'] === 'fichas') { $out['fichas'] += (int)$r['suma']; }
            if ($r['tipo'] === 'pct')    { $out['pct_cantidad'] += (int)$r['cant']; }
            if ($r['tipo'] === 'giro')   { $out['giro_cantidad'] += (int)$r['cant']; }
        }
        return $out;
    } catch (Throwable $e) {
        return ['fichas' => 0, 'pct_cantidad' => 0, 'giro_cantidad' => 0];
    }
}

function movimientos(PDO $pdo, string $usuario, int $limite = 30): array
{
    $st = $pdo->prepare("SELECT tipo, monto, motivo, origen, creado_en
                         FROM movimientos WHERE usuario = ?
                         ORDER BY creado_en DESC LIMIT $limite");
    $st->execute([$usuario]);
    return array_map(function ($m) {
        $m['monto'] = (int)$m['monto'];
        return $m;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

// crm_parse_programada() vive en crm_lib.php (compartida con el cron de
// difusiones de chat, que no puede incluir este archivo entero porque
// dispara exigir_operador()).

/** Marca a un agente como atendiendo un chat (idempotente). */
function crm_agente_tomar(PDO $pdo, int $convId, string $operador): void
{
    if ($operador === '') { return; }
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO conversacion_agentes (conversacion_id, operador) VALUES (?, ?)"
        )->execute([$convId, mb_substr($operador, 0, 60)]);
    } catch (Throwable $e) { /* sin tabla (mig 30 sin correr): se ignora */ }
}

/** Saca a un agente de un chat (relevo / pausa). */
function crm_agente_soltar(PDO $pdo, int $convId, string $operador): void
{
    if ($operador === '') { return; }
    try {
        $pdo->prepare(
            "DELETE FROM conversacion_agentes WHERE conversacion_id = ? AND operador = ?"
        )->execute([$convId, mb_substr($operador, 0, 60)]);
    } catch (Throwable $e) {}
}

/** Lista de agentes atendiendo un chat, el que tomó primero al frente. */
function crm_agentes_de(PDO $pdo, int $convId): array
{
    try {
        $st = $pdo->prepare(
            "SELECT operador FROM conversacion_agentes WHERE conversacion_id = ? ORDER BY tomado_en ASC"
        );
        $st->execute([$convId]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Lista todos los operadores (admins y agentes) de este cliente. */
function crm_agentes_listar(PDO $pdo): array
{
    // rol puede no existir (migración 31 sin correr): se pide con COALESCE
    // vía un try/catch, así no revienta si falta la columna.
    try {
        $rows = $pdo->query(
            "SELECT username, rol, activo, ultimo_login, creado_en FROM operadores
             ORDER BY (rol = 'admin') DESC, username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = $pdo->query(
            "SELECT username, 'admin' AS rol, activo, ultimo_login FROM operadores ORDER BY username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return array_map(function ($r) {
        return [
            'username'     => $r['username'],
            'rol'          => in_array($r['rol'] ?? 'admin', ['admin', 'agente'], true) ? $r['rol'] : 'admin',
            'activo'       => (int)($r['activo'] ?? 1) === 1,
            'ultimo_login' => $r['ultimo_login'] ?? null,
            'creado_en'    => $r['creado_en'] ?? null,
        ];
    }, $rows);
}

/** Crea un agente humano (rol='agente') o resetea su clave si ya existe.
 *  SOLO agentes: crear otro admin no es cosa de este endpoint (ver nota en
 *  la acción agente_crear). */
function crm_agente_crear(PDO $pdo, string $usuario, string $password): array
{
    $usuario = trim($usuario);
    if ($usuario === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $usuario)) {
        return ['ok' => false, 'error' => 'Usuario inválido (3-60 caracteres, sin espacios)'];
    }
    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'error' => 'La contraseña necesita al menos 6 caracteres'];
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    $existe = (bool)$st->fetchColumn();

    try {
        if ($existe) {
            $pdo->prepare("UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?")
                ->execute([$hash, $usuario]);
        } else {
            $pdo->prepare(
                "INSERT INTO operadores (username, password_hash, rol, activo) VALUES (?, ?, 'agente', 1)"
            )->execute([$usuario, $hash]);
        }
    } catch (Throwable $e) {
        // Sin columna `rol` (migración 31 no corrida): igual se crea, va a
        // quedar con el DEFAULT de la tabla (que hoy es 'admin' en muchos
        // casos legacy) hasta que se corra la migración.
        if ($existe) {
            $pdo->prepare("UPDATE operadores SET password_hash = ?, activo = 1 WHERE username = ?")
                ->execute([$hash, $usuario]);
        } else {
            $pdo->prepare(
                "INSERT INTO operadores (username, password_hash, activo) VALUES (?, ?, 1)"
            )->execute([$usuario, $hash]);
        }
    }
    return ['ok' => true, 'usuario' => $usuario, 'accion' => $existe ? 'actualizado' : 'creado'];
}

/** Activa/desactiva un operador (nunca a uno mismo, para no encerrarse). */
function crm_agente_estado(PDO $pdo, string $usuario, int $activo, string $quienLoHace): array
{
    if ($usuario === $quienLoHace) {
        return ['ok' => false, 'error' => 'No podés desactivarte a vos mismo'];
    }
    $pdo->prepare("UPDATE operadores SET activo = ? WHERE username = ?")->execute([$activo, $usuario]);
    return ['ok' => true];
}

/**
 * Cambia el rol de un operador (nunca el propio, para no encerrarse sin
 * ningún admin). $nuevaPassword opcional: si viene (≥6 chars), la resetea
 * en la misma pasada.
 */
function crm_agente_editar(PDO $pdo, string $usuario, string $rol, ?string $nuevaPassword, string $quienLoHace): array
{
    if (!in_array($rol, ['admin', 'agente'], true)) {
        return ['ok' => false, 'error' => 'Rol inválido'];
    }
    if ($usuario === $quienLoHace && $rol !== 'admin') {
        return ['ok' => false, 'error' => 'No podés sacarte el rol admin a vos mismo'];
    }
    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'Ese agente no existe']; }

    if ($nuevaPassword !== null && $nuevaPassword !== '') {
        if (mb_strlen($nuevaPassword) < 6) {
            return ['ok' => false, 'error' => 'La contraseña necesita al menos 6 caracteres'];
        }
        $hash = password_hash($nuevaPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE operadores SET rol = ?, password_hash = ? WHERE username = ?")
            ->execute([$rol, $hash, $usuario]);
    } else {
        $pdo->prepare("UPDATE operadores SET rol = ? WHERE username = ?")->execute([$rol, $usuario]);
    }
    return ['ok' => true];
}

/** Elimina un operador (nunca a uno mismo). Sus mensajes/conversaciones no se
 *  tocan: `mensajes.operador` y `conversacion_agentes.operador` son texto
 *  suelto, no FK, así que la auditoría vieja queda intacta. */
function crm_agente_eliminar(PDO $pdo, string $usuario, string $quienLoHace): array
{
    if ($usuario === $quienLoHace) {
        return ['ok' => false, 'error' => 'No podés eliminarte a vos mismo'];
    }
    $st = $pdo->prepare("SELECT username FROM operadores WHERE username = ? LIMIT 1");
    $st->execute([$usuario]);
    if (!$st->fetchColumn()) { return ['ok' => false, 'error' => 'Ese agente no existe']; }

    // Lo suelta de cualquier chat que estuviera atendiendo, y lo borra.
    try {
        $pdo->prepare("DELETE FROM conversacion_agentes WHERE operador = ?")->execute([$usuario]);
    } catch (Throwable $e) { /* sin tabla (mig 30): nada que soltar */ }
    $pdo->prepare("DELETE FROM operadores WHERE username = ?")->execute([$usuario]);
    return ['ok' => true];
}

/** Carga la lib del chatbot (defaults + armado del prompt) una sola vez. */
function crm_chatbot_lib(): void
{
    $f = __DIR__ . '/chatbot_contexto.php';
    if (is_file($f)) { require_once $f; }
}

/** Defaults del CONTEXTO DINÁMICO (para mostrar en el editor y para el botón
 *  "restaurar"). Vacíos si la lib no está (no rompe el CRM).
 *
 *  `juego_desc` ya NO está acá: de qué trata el juego pasó al contexto FIJO,
 *  que no se edita ni se muestra. Ver api/chatbot_contexto.php. */
function crm_chatbot_defaults(): array
{
    crm_chatbot_lib();
    return [
        'bot_nombre'   => defined('CB_DEF_NOMBRE')       ? CB_DEF_NOMBRE       : '',
        'bot_tono'     => defined('CB_DEF_TONO')         ? CB_DEF_TONO         : '',
        'reglas_extra' => defined('CB_DEF_REGLAS_EXTRA') ? CB_DEF_REGLAS_EXTRA : '',
    ];
}

/** Lee la config del chatbot por CAMPOS. Cada campo vacío en la base se
 *  muestra con su default, así el agente ve y edita desde algo concreto.
 *  Robusto si faltan la tabla (mig. 26) o las columnas (mig. 28). */
function crm_chatbot_leer(PDO $pdo): array
{
    $def = crm_chatbot_defaults();
    try {
        $row = $pdo->query("SELECT * FROM config_chatbot WHERE id = 1 LIMIT 1")
                   ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return array_merge($def, ['activo' => true, 'default' => $def]);
    }
    $val = function($k) use ($row, $def){
        $v = $row ? trim((string)($row[$k] ?? '')) : '';
        return $v !== '' ? $v : ($def[$k] ?? '');
    };
    return [
        'bot_nombre'   => $val('bot_nombre'),
        'bot_tono'     => $val('bot_tono'),
        // reglas_extra NO cae al default: es opcional y su default es vacío.
        'reglas_extra' => $row ? trim((string)($row['reglas_extra'] ?? '')) : '',
        'activo'       => $row ? ((int)($row['activo'] ?? 1) === 1) : true,
        'default'      => $def,   // para el botón "restaurar" del editor
    ];
}

/** Guarda los campos editables + el flag activo. Un campo igual a su default
 *  se guarda NULL, así el cliente hereda futuras mejoras del default. */
function crm_chatbot_guardar(PDO $pdo, array $campos, int $activo): void
{
    $def = crm_chatbot_defaults();
    $norm = function($k) use ($campos, $def){
        $v = trim((string)($campos[$k] ?? ''));
        // Vacío o igual al default -> NULL (sigue el default del código).
        return ($v === '' || $v === trim((string)($def[$k] ?? ''))) ? null : $v;
    };
    /* `juego_desc` queda FUERA del UPDATE a proposito, no es un olvido.
       Dejo de ser un campo del CRM (paso al contexto fijo), asi que el editor
       ya no lo manda; si siguiera en la lista, el primer "Guardar" lo pisaria
       con NULL y ese cliente perderia en silencio el texto que habia escrito.
       chatbot.php lo sigue leyendo y, si esta personalizado, lo suma como
       informacion del operador. Ver chatbot_contexto_dinamico(). */
    $pdo->prepare(
        "INSERT INTO config_chatbot (id, bot_nombre, bot_tono, reglas_extra, activo)
         VALUES (1, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           bot_nombre = VALUES(bot_nombre), bot_tono = VALUES(bot_tono),
           reglas_extra = VALUES(reglas_extra),
           activo = VALUES(activo)"
    )->execute([
        $norm('bot_nombre'), $norm('bot_tono'),
        $norm('reglas_extra'), $activo ? 1 : 0,
    ]);
}

// =============================== GET ========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion = (string)($_GET['accion'] ?? 'conversaciones');

    try {
        // ---- lista de conversaciones ----
        if ($accion === 'conversaciones') {
            $q      = trim((string)($_GET['q'] ?? ''));
            $estado = (string)($_GET['estado'] ?? 'todas');

            $where = [];
            $params = [];
            if ($q !== '') {
                $where[] = '(c.usuario LIKE ? OR c.session_id LIKE ? OR c.preview LIKE ?)';
                $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
            }
            if (in_array($estado, ['abierta', 'pendiente', 'cerrada'], true)) {
                $where[] = 'c.estado = ?';
                $params[] = $estado;
            }

            /* LEIDO / NO LEIDO. Es la pregunta que el agente se hace primero al
               abrir la bandeja ("¿que me falta mirar?") y no se podia filtrar:
               el contador `no_leidos` ya estaba, pero solo pintaba el globito.
               NO es un valor del ENUM `estado` -- una conversacion no leida
               puede estar abierta, pendiente o cerrada -- pero viaja por el
               mismo parametro porque para el agente es una pestaña mas.
               'no_leidas' se cruza con archivada = 0 como cualquier otra
               pestaña de bandeja (las archivadas ya se dan por leidas al
               archivarse, asi que no habria ninguna igual). */
            if ($estado === 'no_leidas') {
                $where[] = 'c.no_leidos > 0';
            } elseif ($estado === 'leidas') {
                $where[] = 'COALESCE(c.no_leidos, 0) = 0';
            }

            /* Archivadas: se ven SOLO en su pestaña. Es lo que hace que
               archivar sirva -- si siguieran apareciendo en "Todas", sacarlas
               de la bandeja no sacaria nada.

               La pestaña es un valor de `estado` en el front pero NO toca el
               ENUM: una archivada conserva si estaba abierta o pendiente.

               DOS EXCEPCIONES, y las dos salen del mismo problema real: un
               jugador que hablo y cargo dejaba de existir para el agente en
               cuanto alguien archivaba su chat.

                 - 'todo': la vista completa, como la lista de WhatsApp. Es una
                   pestaña aparte y no el default, porque "Todas" tiene que
                   seguir siendo la BANDEJA (lo que falta atender).
                 - Con BUSQUEDA puesta no se filtra por archivada. Buscar a
                   alguien por nombre y que no aparezca -estando en la base- es
                   la peor respuesta posible: el agente concluye que el jugador
                   no existe. Si lo buscas por nombre, ya sabes a quien queres:
                   el archivado no es un filtro, es un orden de bandeja. */
            if (crm_hay_archivada($pdo)) {
                if ($estado === 'archivadas') {
                    $where[] = 'c.archivada = 1';
                } elseif ($estado !== 'todo' && $q === '') {
                    $where[] = 'c.archivada = 0';
                }
            } elseif ($estado === 'archivadas') {
                // Sin la migracion no hay archivadas. Devolver la lista entera
                // seria peor que devolver nada: parece que se archivo todo.
                $where[] = '1 = 0';
            }

            /* Inactividad: MISMA definicion que la tabla de Usuarios y que el
               push masivo -- sin recarga acreditada en N dias. Que "inactivo"
               signifique lo mismo en las tres pantallas no es cosmetico: el
               agente filtra aca, abre el chat y le manda una promo; si cada
               lista contara distinto, el numero que vio no seria el que
               atiende.

               OJO: se piden conversaciones CON usuario. Un chat anonimo no
               tiene recargas, asi que sin este filtro entrarian todos y el
               listado de "inactivos" seria mayormente ruido de gente que
               nunca se identifico. */
            $inact = (int)($_GET['inactivos'] ?? 0);
            // Dos preguntas distintas y el agente elige cual:
            //   carga -> hace N dias que no pone plata  (recargas)
            //   chat  -> hace N dias que no escribe     (actualizada_en)
            // Un jugador puede estar activo en una y muerto en la otra: el que
            // charla todos los dias pero no carga hace un mes es justo al que
            // hay que ir a buscar, y con un solo criterio no aparece.
            $inactTipo = ($_GET['inactivos_tipo'] ?? 'carga') === 'chat' ? 'chat' : 'carga';

            /* Orden. UNO de tres, excluyentes -- no se combinan:
                 reciente   el que hablo ultimo, arriba  (default)
                 antiguo    el que hablo hace mas tiempo, arriba
                 inactivos  por el criterio de inactividad elegido

               "Quien hablo ultimo" es literalmente c.actualizada_en: crm_lib
               la pisa con NOW() en cada mensaje, del jugador o del agente. No
               hace falta salir a mirar `mensajes`. */
            $orden     = (string)($_GET['orden'] ?? 'reciente');
            $hayFiltro = in_array($inact, [15, 30, 90], true);
            // "Mas inactivos" sin filtro puesto ordenaria la lista entera por
            // algo que el agente no pidio, asi que se ignora.
            if ($orden === 'inactivos' && !$hayFiltro) { $orden = 'reciente'; }

            /* Fecha de la ultima recarga acreditada. Se calcula aparte porque
               la usan el filtro Y el orden: sin esto habria que repetir la
               subconsulta en los dos lugares y que no se despeguen.

               COLLATE explicito y NO es decorativo: `conversaciones` declara
               utf8mb4_unicode_ci (migracion 05) y `recargas` no declara
               ninguna (migracion 02), asi que cae en el default del servidor.
               Sin esto, "Illegal mix of collations" se lleva puesta la lista
               entera. Misma trampa que documenta CLAUDE.md. */
            $ultimaCarga = "(SELECT MAX(r.acreditada_en) FROM recargas r
                              WHERE r.usuario COLLATE utf8mb4_unicode_ci
                                    = c.usuario COLLATE utf8mb4_unicode_ci
                                AND r.estado = 'acreditada')";

            if ($hayFiltro) {
                if ($inactTipo === 'chat') {
                    // Sin escribir: la propia conversacion lo dice. Entran los
                    // anonimos tambien -- un chat abandonado es un chat
                    // abandonado, con nombre o sin el.
                    $where[]  = "c.actualizada_en < DATE_SUB(NOW(), INTERVAL ? DAY)";
                    $params[] = $inact;
                } else {
                    // Sin cargar: hace falta usuario. Un chat anonimo no tiene
                    // recargas, asi que sin este filtro entrarian todos y la
                    // lista seria ruido de gente que nunca se identifico.
                    $where[]  = "c.usuario IS NOT NULL AND c.usuario <> ''
                                 AND COALESCE($ultimaCarga, '1000-01-01')
                                     < DATE_SUB(NOW(), INTERVAL ? DAY)";
                    $params[] = $inact;
                }
            }

            $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            /* Las fijadas van SIEMPRE arriba, en los tres ordenes: el agente
               las clavo ahi por algo y un cambio de orden no deshace eso. */
            if ($orden === 'inactivos') {
                // Por la fecha del criterio elegido, ascendente: el que hace
                // mas tiempo que no aparece queda arriba.
                $ordenSql = $inactTipo === 'chat'
                    ? 'c.fijada DESC, c.actualizada_en ASC'
                    // COALESCE con una fecha imposible: el que NUNCA cargo es
                    // el mas inactivo de todos y tiene que salir primero, no
                    // ultimo por ser NULL.
                    : "c.fijada DESC, COALESCE($ultimaCarga, '1000-01-01') ASC";
            } elseif ($orden === 'antiguo') {
                // El que hablo hace mas tiempo, arriba. Sirve para barrer la
                // cola vieja sin que la tapen los chats de hace un minuto.
                $ordenSql = 'c.fijada DESC, c.actualizada_en ASC, c.id ASC';
            } else {
                $ordenSql = 'c.fijada DESC, c.actualizada_en DESC, c.id DESC';
            }

            // Las columnas de la derivacion (migracion 49) solo se piden si
            // estan: sin este guard, una base sin migrar se queda sin bandeja.
            $hayDeriv  = crm_hay_derivada($pdo);
            $selDeriv  = $hayDeriv ? 'c.derivada_en, c.derivada_motivo,' : '';
            // Si una fila archivada puede aparecer fuera de su pestaña (vista
            // 'todo' o busqueda), el agente tiene que ver POR QUE no estaba en
            // la bandeja. Sin el chip parece un chat comun que se le paso.
            $selArch   = crm_hay_archivada($pdo) ? 'c.archivada,' : '';
            /* Si el BOT esta prendido en cada chat (migracion 27). La bandeja
               no lo mostraba, asi que no habia forma de ver de un vistazo
               donde quedo mudo -- y queda mudo solo, cada vez que un agente
               responde. Mismo guard que las otras: una base sin migrar tiene
               que seguir teniendo bandeja. */
            $hayIa     = true;
            try { $pdo->query("SELECT ia_activa FROM conversaciones LIMIT 0"); }
            catch (Throwable $e) { $hayIa = false; }
            $selIa     = $hayIa ? 'c.ia_activa,' : '';

            /* Las derivadas suben, arriba de todo menos de las fijadas.
               Esto es lo que hace que la derivacion sea un AVISO y no una
               marquita: en una bandeja de 53 conversaciones, un jugador al que
               el bot le prometio un agente no puede quedar en la posicion 40
               porque hablo hace rato. Se respeta `fijada` por encima: eso lo
               clavo el agente a mano y no se lo pisamos.
               Vale para los tres ordenes, igual que fijada. */
            if ($hayDeriv) {
                $ordenSql = preg_replace(
                    '/^c\.fijada DESC, /',
                    'c.fijada DESC, (c.derivada_en IS NOT NULL) DESC, ',
                    $ordenSql, 1
                );
            }

            $st = $pdo->prepare(
                /* `hablo` = si el JUGADOR escribio alguna vez. No alcanza con
                   mirar si hay mensajes: una conversacion sembrada desde el CRM
                   ya tiene uno, el nuestro. Lo que distingue "chat vacio" de
                   "chat de verdad" es un mensaje con rol='user'.
                   El front lo usa para marcar de una todos los que nunca
                   hablaron, en vez de tildarlos de a uno. */
                "SELECT c.id, c.session_id, c.usuario, c.estado, c.preview,
                        c.no_leidos, c.fijada, c.actualizada_en, $selDeriv $selArch $selIa
                        EXISTS (SELECT 1 FROM mensajes m
                                 WHERE m.conversacion_id = c.id AND m.rol = 'user') AS hablo,
                        $ultimaCarga AS ultima_carga
                 FROM conversaciones c $wsql
                 ORDER BY $ordenSql LIMIT 200"
            );
            $st->execute($params);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['no_leidos'] = (int)$it['no_leidos'];
                $it['fijada'] = (bool)$it['fijada'];
                $it['agentes'] = [];
                // Booleano para el front: le alcanza con "el bot pidio ayuda".
                $it['derivada'] = !empty($it['derivada_en']);
                $it['archivada'] = !empty($it['archivada']);
                $it['hablo'] = !empty($it['hablo']);
                /* Sin la columna se asume PRENDIDO, que es el default de la
                   migracion 27 y el estado normal: pintar "apagado" donde no
                   se sabe seria mandar al operador a revisar chats sanos. */
                $it['ia_activa'] = !array_key_exists('ia_activa', $it)
                                 || $it['ia_activa'] === null
                                 || (int)$it['ia_activa'] === 1;
            }
            unset($it);

            // Agentes atendiendo cada chat del listado, en UNA query (sin N+1).
            if ($items) {
                try {
                    $ids = array_column($items, 'id');
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    $stA = $pdo->prepare(
                        "SELECT conversacion_id, operador FROM conversacion_agentes
                         WHERE conversacion_id IN ($ph) ORDER BY tomado_en ASC"
                    );
                    $stA->execute($ids);
                    $porConv = [];
                    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $porConv[(int)$r['conversacion_id']][] = $r['operador'];
                    }
                    foreach ($items as &$it2) {
                        $it2['agentes'] = $porConv[$it2['id']] ?? [];
                    }
                    unset($it2);
                } catch (Throwable $e) { /* sin tabla (mig 30): quedan [] */ }
            }

            /* ---- "Sin hablar": los que NO tienen ni conversacion ----
               La lista sale de `conversaciones`, asi que el que se creo la
               cuenta y nunca escribio no aparecia en ningun lado -- y es
               justamente a quien uno quiere ir a buscar. Entran como filas
               VIRTUALES (id 0): no existe ninguna conversacion todavia y no se
               crea hasta que el agente le escriba (accion=chat_de_usuario).
               Crear cientos de chats vacios de antemano taparia los que si
               esperan respuesta.
               Van al final, despues de los chats de verdad: son otra cosa. */
            $sinChat = 0;
            if ($inactTipo === 'chat') {
                $faltan = 200 - count($items);
                if ($faltan > 0) {
                    foreach (crm_usuarios_sin_conversacion($pdo, $inact, $faltan) as $u) {
                        $items[] = [
                            'id'             => 0,
                            'sin_chat'       => true,
                            'session_id'     => '',
                            'usuario'        => $u['username'],
                            'estado'         => 'abierta',
                            'preview'        => '',
                            'no_leidos'      => 0,
                            'fijada'         => false,
                            'actualizada_en' => $u['creation_date'],
                            'ultima_carga'   => null,
                            'agentes'        => [],
                            'derivada'       => false,
                        ];
                        $sinChat++;
                    }
                }
            }

            /* Los totales del panel cuentan la BANDEJA, no la base: una
               conversacion archivada no es un pendiente que alguien tenga que
               atender, y si contara, el numero de "abiertas" nunca bajaria por
               archivar y el agente no vería el efecto de haber ordenado. */
            $vivas   = crm_hay_archivada($pdo) ? 'WHERE archivada = 0' : '';
            // Cuantas espera un agente porque las derivo el bot. Es el numero
            // del badge del rail: mide gente esperando, no mensajes sin leer.
            $sumDeriv = $hayDeriv ? "SUM(derivada_en IS NOT NULL) derivadas," : '0 derivadas,';
            $res = $pdo->query(
                "SELECT COUNT(*) total,
                        SUM(estado='abierta')   abiertas,
                        SUM(estado='pendiente') pendientes,
                        $sumDeriv
                        SUM(no_leidos>0)        con_no_leidos
                 FROM conversaciones $vivas"
            )->fetch(PDO::FETCH_ASSOC);

            $archivadas = 0;
            if (crm_hay_archivada($pdo)) {
                $archivadas = (int)$pdo->query(
                    "SELECT COUNT(*) FROM conversaciones WHERE archivada = 1"
                )->fetchColumn();
            }

            // Totales de la base (para las estadisticas del panel lateral).
            $g = $pdo->query(
                "SELECT COUNT(*) usuarios, COALESCE(SUM(balance),0) saldo,
                        COALESCE(SUM(coins),0) fichas, COALESCE(SUM(bonus),0) bonos
                 FROM usuarios"
            )->fetch(PDO::FETCH_ASSOC);

            salir(['ok' => true, 'items' => $items, 'sin_chat' => $sinChat, 'resumen' => [
                'total'         => (int)$res['total'],
                'abiertas'      => (int)$res['abiertas'],
                'pendientes'    => (int)$res['pendientes'],
                'derivadas'     => (int)$res['derivadas'],
                'con_no_leidos' => (int)$res['con_no_leidos'],
                'archivadas'    => $archivadas,
                'usuarios'      => (int)$g['usuarios'],
                'saldo_interno' => (float)$g['saldo'],
                'fichas_total'  => (int)$g['fichas'],
                'bonos_total'   => (int)$g['bonos'],
            ]]);
        }

        /* ---- abrir el chat de un jugador que todavia no tiene ----
           Lo llama el front cuando el agente clickea una de las filas virtuales
           de "sin hablar". La conversacion nace ACA, en el momento en que
           alguien decide escribirle -- no antes, para no llenar la bandeja de
           chats vacios.

           Es idempotente: crm_conversacion_id() devuelve la que ya exista para
           ese usuario. Dos agentes clickeando al mismo tiempo abren la misma,
           no dos.

           El session_id de relleno lleva prefijo 'crm:' para que se vea de
           donde salio. Cuando el jugador entre y se identifique,
           crm_conversacion_id le pega el suyo real (la rama que ya existia para
           el que vuelve desde otro celular) y ahi recien se lleva lo que le
           hayamos escrito -- mis_mensajes.php empareja por session_id. */
        if ($accion === 'chat_de_usuario') {
            $u = trim((string)($_GET['usuario'] ?? ''));
            if ($u === '') { salir(['ok' => false, 'error' => 'Falta el usuario'], 400); }

            $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
            $st->execute([$u]);
            if (!$st->fetchColumn()) {
                salir(['ok' => false, 'error' => 'Ese jugador no existe'], 404);
            }

            $convId = crm_conversacion_id($pdo, 'crm:' . mb_substr($u, 0, 50), $u);
            salir(['ok' => true, 'id' => $convId, 'usuario' => $u]);
        }

        // ---- una conversacion con su hilo + ficha del usuario ----
        if ($accion === 'conversacion') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $st = $pdo->prepare("SELECT * FROM conversaciones WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $conv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$conv) { salir(['ok' => false, 'error' => 'No existe'], 404); }
            $conv['id'] = (int)$conv['id'];
            $conv['no_leidos'] = (int)$conv['no_leidos'];
            $conv['fijada'] = (bool)($conv['fijada'] ?? false);
            // Puede no existir si la migracion 41 no se corrio: default false.
            $conv['archivada'] = (bool)($conv['archivada'] ?? false);
            // ia_activa puede no existir si la migracion 27 no se corrio: default true.
            $conv['ia_activa'] = !array_key_exists('ia_activa', $conv) || (int)$conv['ia_activa'] === 1;

            // marcar como leida
            $pdo->prepare("UPDATE conversaciones SET no_leidos = 0 WHERE id = ?")->execute([$id]);

            /* VISTO del agente (migracion 57): el hilo esta EN PANTALLA, asi
               que los mensajes del jugador quedan vistos ahora. Es lo que el
               widget usa para pintar las tildes azules de verdad (antes eran
               un timer decorativo). Que el sondeo de 9s tambien pase por aca
               esta bien: la conversacion sigue abierta delante del agente.
               Best-effort: sin la migracion, el hilo carga igual. */
            try {
                $pdo->prepare(
                    "UPDATE mensajes SET visto_en = NOW()
                      WHERE conversacion_id = ? AND rol = 'user' AND visto_en IS NULL"
                )->execute([$id]);
            } catch (Throwable $e) { /* sin migracion 57 */ }

            // `operador` por mensaje (migración 30). Si la columna no existe,
            // se reintenta sin ella para no romper el hilo. visto_en (57) y
            // borrado_en/borrado_por (58) llegan juntos en el primer intento:
            // con cualquiera de esas migraciones sin correr se degrada al
            // fallback y el hilo carga igual, sin visto ni borrar.
            try {
                $st = $pdo->prepare("SELECT id, rol, operador, texto, meta, creado_en, visto_en,
                                            borrado_en, borrado_por FROM mensajes
                                     WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
                $st->execute([$id]);
            } catch (Throwable $e) {
                try {
                    $st = $pdo->prepare("SELECT id, rol, operador, texto, meta, creado_en FROM mensajes
                                         WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
                    $st->execute([$id]);
                } catch (Throwable $e2) {
                    $st = $pdo->prepare("SELECT id, rol, texto, meta, creado_en FROM mensajes
                                         WHERE conversacion_id = ? ORDER BY creado_en ASC, id ASC");
                    $st->execute([$id]);
                }
            }
            $mensajes = array_map(function ($m) {
                $meta = $m['meta'] ? json_decode($m['meta'], true) : null;
                // adjunto: {tipo,url,nombre}. interno: true = rastro del agente
                // ("Fiorella cargó $500"), no una respuesta real al cliente.
                $m['id'] = (int)$m['id'];
                $m['adjunto'] = ($meta && isset($meta['url'])) ? $meta : null;
                $m['interno'] = (bool)($meta['interno'] ?? false);
                unset($m['meta']);
                // Un mensaje eliminado no viaja con su texto: el hilo muestra
                // el rastro ("Mensaje eliminado"), no el contenido borrado.
                if (!empty($m['borrado_en'])) { $m['texto'] = ''; $m['adjunto'] = null; }
                return $m;
            }, $st->fetchAll(PDO::FETCH_ASSOC));

            $usuario = trim((string)($conv['usuario'] ?? ''));
            $ficha = $usuario !== '' ? ficha_usuario($pdo, $usuario) : null;
            $movs  = $usuario !== '' ? movimientos($pdo, $usuario) : [];

            salir(['ok' => true, 'conversacion' => $conv, 'mensajes' => $mensajes,
                   'usuario' => $ficha, 'movimientos' => $movs,
                   'agentes' => crm_agentes_de($pdo, $id),
                   'yo' => $operador]);
        }

        // ---- plantillas de mensaje ----
        if ($accion === 'plantillas') {
            try {
                $items = $pdo->query(
                    "SELECT id, comando, texto, atajo FROM plantillas_mensaje ORDER BY comando"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // Sin la migracion 42 no hay plantillas: lista vacia, no error.
                // El composer tiene que andar igual en una base vieja.
                $items = [];
            }
            salir(['ok' => true, 'items' => $items]);
        }

        // ---- config del chatbot (campos editables + on/off) ----
        if ($accion === 'chatbot_config') {
            salir(array_merge(['ok' => true], crm_chatbot_leer($pdo)));
        }

        // ---- quién soy (para recuperar el rol tras un F5, CSRF/ROL no
        //      persisten en localStorage a propósito) ----
        // ---- ajustes del sitio (vista Configuracion) ----
        if ($accion === 'config') {
            salir(['ok' => true, 'config' => cfg_crm_todo($pdo)]);
        }

        // ---- ultimas recaudaciones (para la vista Recaudar del CRM) ----
        // Sin la migracion 60 la tabla no existe: lista vacia, no error, para
        // que la vista abra igual en una base que todavia no la corrio.
        if ($accion === 'recaudar_estado') {
            try {
                $filas = $pdo->query(
                    "SELECT id, estado, dry_run, dias, saltar, tope, min_saldo,
                            pedido_por, resultado, mensaje, creada_en, actualizada_en
                       FROM recaudaciones ORDER BY id DESC LIMIT 15"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $filas = [];
            }
            foreach ($filas as &$f) {
                $f['dry_run']   = (int)$f['dry_run'] === 1;
                $f['resultado'] = $f['resultado'] ? json_decode($f['resultado'], true) : null;
            }
            salir(['ok' => true, 'recaudaciones' => $filas]);
        }

        // ---- campaña de fidelizacion (vista Fidelizacion del CRM) ----
        // Config + numeros de rendimiento. Sin la migracion 65 degrada a
        // stats vacias para que la vista abra igual.
        if ($accion === 'fid_estado') {
            require_once __DIR__ . '/fidelizacion_lib.php';
            $tramos = fid_tramos($pdo);
            $stats  = ['avisos_30d' => 0, 'por_tramo' => [], 'bonos_cobrados' => 0,
                       'giros_dados' => 0, 'ultimos' => []];
            try {
                $stats['avisos_30d'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM fidelizacion_avisos
                      WHERE enviado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                )->fetchColumn();
                foreach ($pdo->query(
                    "SELECT dias, COUNT(*) n FROM fidelizacion_avisos
                      WHERE enviado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY dias ORDER BY dias"
                ) as $f) {
                    $stats['por_tramo'][(int)$f['dias']] = (int)$f['n'];
                }
                $stats['ultimos'] = $pdo->query(
                    "SELECT usuario, dias, pct, ruleta, enviado_en
                       FROM fidelizacion_avisos ORDER BY id DESC LIMIT 20"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* sin migracion 65 */ }
            try {
                // Lo COBRADO de verdad: bonos de la campaña que una recarga aplico.
                $stats['bonos_cobrados'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM bonos_pendientes
                      WHERE prometido_por = 'fidelizacion' AND estado = 'aplicado'"
                )->fetchColumn();
                $stats['giros_dados'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM bonos_pendientes
                      WHERE prometido_por = 'fidelizacion' AND tipo = 'giro'"
                )->fetchColumn();
            } catch (Throwable $e) { /* sin migracion 33 */ }
            /* ALCANCE: cuantos jugadores estan HOY en la banda de cada
               escalon (entre sus dias y los del siguiente; el ultimo es
               "o mas"). Le responde al admin "¿a cuanta gente le va a llegar
               esto?" ANTES de prender el switch. */
            $alcance = [];
            try {
                foreach ($tramos as $i => $t) {
                    $desde = (int)$t['dias'];
                    $hasta = isset($tramos[$i + 1]) ? (int)$tramos[$i + 1]['dias'] : null;
                    $sql = "SELECT COUNT(*) FROM usuarios
                             WHERE ultima_actividad IS NOT NULL AND is_banned = 0
                               AND ultima_actividad <= DATE_SUB(NOW(), INTERVAL $desde DAY)";
                    if ($hasta !== null) {
                        $sql .= " AND ultima_actividad > DATE_SUB(NOW(), INTERVAL $hasta DAY)";
                    }
                    $alcance[$desde] = (int)$pdo->query($sql)->fetchColumn();
                }
            } catch (Throwable $e) { /* sin dato, la vista muestra guiones */ }

            salir(['ok' => true,
                   'activa' => cfg_crm_activo($pdo, 'fid_activa'),
                   'tramos' => $tramos,
                   'alcance' => $alcance,
                   'ultima_pasada' => (string)(cfg_crm($pdo, 'fid_visto_en') ?? ''),
                   'stats'  => $stats]);
        }

        // ---- instalaciones de la app (vista App del CRM) ----
        // Numeros de la promo "descarga la app": cuantos la tienen, cuantos
        // entraron por primera vez hoy / esta semana, y los bonos EFECTIVAMENTE
        // pagados (movimientos origen 'bono_app', el candado del regalo). La
        // lista sale de `dispositivos` (android con usuario), que ademas dice
        // desde que celular y cuando se lo vio por ultima vez.
        if ($accion === 'app_stats') {
            $u = $pdo->query(
                "SELECT COUNT(*) total, COALESCE(SUM(tiene_app),0) con_app FROM usuarios"
            )->fetch(PDO::FETCH_ASSOC);

            // Bonos pagados. Try/catch propio: sin movimientos no se cae la vista.
            try {
                $b = $pdo->query(
                    "SELECT COUNT(*) n, COALESCE(SUM(monto),0) fichas,
                            SUM(creado_en >= CURDATE()) hoy,
                            SUM(creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)) semana
                       FROM movimientos WHERE origen = 'bono_app' AND monto > 0"
                )->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $b = ['n' => 0, 'fichas' => 0, 'hoy' => 0, 'semana' => 0];
            }

            /* Instalaciones por fecha: el primer celular android de cada
               jugador. Sin JOIN a usuarios a proposito (choque de collations,
               ver CLAUDE.md): dispositivos ya tiene el usuario. */
            try {
                $t = $pdo->query(
                    "SELECT SUM(primera >= CURDATE()) hoy,
                            SUM(primera >= DATE_SUB(NOW(), INTERVAL 7 DAY)) semana
                       FROM (SELECT usuario, MIN(creado_en) primera
                               FROM dispositivos
                              WHERE plataforma = 'android' AND usuario IS NOT NULL
                              GROUP BY usuario) d"
                )->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $t = ['hoy' => 0, 'semana' => 0];
            }

            try {
                $lista = $pdo->query(
                    "SELECT usuario, modelo, version, permitido, creado_en, visto_en
                       FROM dispositivos
                      WHERE plataforma = 'android' AND usuario IS NOT NULL
                      ORDER BY creado_en DESC LIMIT 50"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $lista = [];
            }
            // El bono de cada uno, sin JOIN (misma razon): un IN con los
            // usuarios de la pagina. Como mucho 50.
            $conBono = [];
            if ($lista) {
                $usuarios = array_values(array_unique(array_column($lista, 'usuario')));
                $marcas = implode(',', array_fill(0, count($usuarios), '?'));
                try {
                    $st = $pdo->prepare(
                        "SELECT DISTINCT usuario FROM movimientos
                          WHERE origen = 'bono_app' AND monto > 0 AND usuario IN ($marcas)"
                    );
                    $st->execute($usuarios);
                    $conBono = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
                } catch (Throwable $e) {}
            }
            foreach ($lista as &$d) {
                $d['permitido'] = (int)$d['permitido'] === 1;
                $d['bono']      = isset($conBono[$d['usuario']]);
            }

            salir(['ok' => true, 'resumen' => [
                'total_usuarios'  => (int)$u['total'],
                'con_app'         => (int)$u['con_app'],
                'instaladas_hoy'    => (int)($t['hoy'] ?? 0),
                'instaladas_semana' => (int)($t['semana'] ?? 0),
                'bonos_entregados' => (int)$b['n'],
                'bonos_fichas'     => (int)$b['fichas'],
                'bonos_hoy'        => (int)($b['hoy'] ?? 0),
                'bonos_semana'     => (int)($b['semana'] ?? 0),
            ], 'dispositivos' => $lista]);
        }

        if ($accion === 'yo') {
            // El CSRF viaja acá porque el front lo pierde en cada F5 (vive solo
            // en memoria a propósito). Sin esto, después de recargar la página
            // cualquier POST que no pase por apiFetch() -- que reacciona al 403
            // mostrando el login -- se queda sin token y falla en silencio.
            salir(['ok' => true, 'operador' => $operador, 'rol' => operador_rol(),
                   'csrf' => csrf_token()]);
        }

        // ---- listar agentes/admins (solo admin) ----
        if ($accion === 'agentes_listar') {
            exigir_admin();
            salir(['ok' => true, 'agentes' => crm_agentes_listar($pdo)]);
        }

        // ---- difusiones programadas que todavía no salieron ----
        if ($accion === 'programadas_listar') {
            $push = array_map(function ($p) {
                $p['canal'] = 'push';
                return $p;
            }, notif_programadas_listar($pdo));
            $chat = array_map(function ($p) {
                $p['canal']  = 'chat';
                $p['titulo'] = $p['texto'];   // mismo campo que usa el front para mostrar
                return $p;
            }, crm_difusiones_chat_listar($pdo));
            $todas = array_merge($push, $chat);
            usort($todas, fn($a, $b) => strcmp($a['programada_en_ar'], $b['programada_en_ar']));
            salir(['ok' => true, 'programadas' => $todas]);
        }

        // ---- historial de notificaciones YA enviadas (a diferencia de
        //      programadas_listar, que solo mira las futuras) ----
        if ($accion === 'notif_historial') {
            $opts = [
                'usuario' => (string)($_GET['usuario'] ?? ''),
                'desde'   => (string)($_GET['desde'] ?? ''),
                'hasta'   => (string)($_GET['hasta'] ?? ''),
                'tipo'    => (string)($_GET['tipo'] ?? ''),
                'pagina'  => (int)($_GET['pagina'] ?? 1),
                'solo_difusiones' => !empty($_GET['solo_difusiones']),
            ];
            salir(array_merge(['ok' => true], crmnotif_historial($pdo, $opts)));
        }

        // ---- preview de alcance para el filtro "inactivos hace N días" ----
        // Cuantos jugadores nunca escribieron. Se mira ANTES de mandar: este
        // envio les crea la conversacion a todos, asi que conviene saber si son
        // 12 o 1.200 antes de llenar la bandeja.
        if ($accion === 'notif_alcance_sin_chat') {
            salir(['ok' => true, 'alcance' => count(crmnotif_usuarios_sin_chat($pdo))]);
        }

        // Celulares activos (30 dias) que recibirian una difusion a "Todos".
        // La vista lo muestra en la tarjeta de audiencia, como los otros dos.
        if ($accion === 'notif_alcance_todos') {
            salir(['ok' => true, 'alcance' => notif_alcance($pdo, null)]);
        }

        if ($accion === 'notif_alcance_inactivos') {
            $dias = (int)($_GET['dias'] ?? 0);
            salir(['ok' => true, 'alcance' => crmnotif_alcance_inactivos($pdo, $dias)]);
        }

        // ---- presets de filtro guardados ----
        if ($accion === 'notif_presets_listar') {
            salir(['ok' => true, 'presets' => crmnotif_presets_listar($pdo)]);
        }

        // ---- bonos pendientes (catálogo de lo prometido por notificación) ----
        if ($accion === 'bonos_listar') {
            $opts = [
                'usuario' => (string)($_GET['usuario'] ?? ''),
                'estado'  => (string)($_GET['estado'] ?? ''),
            ];
            salir(['ok' => true, 'bonos' => crmnotif_bono_listar($pdo, $opts)]);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm GET: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error al consultar', 'detalle' => $e->getMessage()], 500);
    }
}

// =============================== POST =======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $accion = (string)($body['accion'] ?? '');

    try {
        // ---- notas internas ----
        if ($accion === 'nota') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $notas = mb_substr((string)($body['notas'] ?? ''), 0, 4000);
            $pdo->prepare("UPDATE conversaciones SET notas = ? WHERE id = ?")->execute([$notas, $id]);
            salir(['ok' => true]);
        }

        // ---- cambiar estado ----
        if ($accion === 'estado') {
            $id = (int)($body['id'] ?? 0);
            $estado = (string)($body['estado'] ?? '');
            if (!$id || !in_array($estado, ['abierta', 'pendiente', 'cerrada'], true)) {
                salir(['ok' => false, 'error' => 'Datos invalidos'], 400);
            }
            $pdo->prepare("UPDATE conversaciones SET estado = ? WHERE id = ?")->execute([$estado, $id]);
            /* Cerrar tambien baja el «Te necesita». Un ticket cerrado no puede
               seguir diciendo que alguien lo espera: quedaba arriba de la
               bandeja y sumando al badge del rail, y desde aca no habia forma
               de sacarlo. Solo al CERRAR -- pasar a 'pendiente' o reabrir no
               resuelve nada, y borrar la marca ahi escondería el pedido. */
            if ($estado === 'cerrada') { crm_bajar_derivada($pdo, [$id]); }
            salir(['ok' => true]);
        }

        /* Los ids de una accion en lote. Acepta `ids` (array) o el `id`
           suelto de siempre, asi los llamadores viejos siguen andando. Tope
           de 200: es lo que la lista muestra como maximo, y un lote mas
           grande que la pantalla es un bug del cliente, no un pedido. */
        $lote = function (array $body): array {
            $ids = [];
            foreach ((array)($body['ids'] ?? []) as $x) {
                if ((int)$x > 0) { $ids[] = (int)$x; }
            }
            if (!$ids && (int)($body['id'] ?? 0) > 0) { $ids[] = (int)$body['id']; }
            return array_slice(array_values(array_unique($ids)), 0, 200);
        };

        // ---- archivar / desarchivar (uno o varios) ----
        /* MARCAR COMO LEIDAS, en lote.
           El globito de "99+" se apagaba de a una, abriendo cada conversacion.
           Con 2.946 chats --y la mayoria difusiones que nadie contesto-- ese
           numero no dice nada y se vuelve invisible, que es lo peor que le
           puede pasar a un contador: el dia que hay tres de verdad, no se ven.

           Dos modos, y la diferencia importa: `ids` marca lo SELECCIONADO;
           `todas: true` marca TODAS las que tengan sin leer. El segundo existe
           porque Ctrl+A solo alcanza lo que esta cargado en pantalla (LIMIT
           200), asi que con miles de conversaciones el operador tendria que
           repetir la operacion quince veces sin entender por que el numero no
           baja.

           NO toca ninguna otra cosa: ni archiva, ni cierra, ni baja el
           «Te necesita». Marcar leido es decir "ya lo vi", no "ya lo resolvi". */
        /* BLOQUEAR / DESBLOQUEAR. Es una decision de una persona, siempre:
           las señales que junta vinculos_lib avisan, no deciden. Bloquear por
           IP a dos hermanos que juegan de la misma casa es perder dos clientes
           reales para atajar a uno falso. */
        if ($accion === 'bloquear') {
            if (is_file(__DIR__ . '/vinculos_lib.php')) {
                require_once __DIR__ . '/vinculos_lib.php';
            }
            if (!function_exists('vin_bloquear')) {
                salir(['ok' => false, 'error' => 'Falta correr la migración 69'], 400);
            }
            $u   = trim((string)($body['usuario'] ?? ''));
            $on  = !empty($body['bloquear']);
            $mot = trim((string)($body['motivo'] ?? ''));

            /* `con_vinculadas` bloquea a la PERSONA y no a una cuenta. Es lo
               que hace efectivo el bloqueo: dejar dos de tres abiertas no frena
               nada. Arrastra solo por señales fuertes (comprobante, banco,
               celular) -- nunca por IP. */
            $res = !empty($body['con_vinculadas']) && function_exists('vin_bloquear_grupo')
                 ? vin_bloquear_grupo($pdo, $u, $on, $operador, $mot)
                 : vin_bloquear($pdo, $u, $on, $operador, $mot);

            if ($res['ok']) {
                $tocadas = $res['usuarios'] ?? [$u];
                crm_bitacora($pdo, $operador,
                             $on ? 'bloquear' : 'desbloquear',
                             implode(', ', $tocadas) . ($mot !== '' ? ' · ' . $mot : ''));
            }
            salir($res, $res['ok'] ? 200 : 400);
        }

        if ($accion === 'marcar_leidas') {
            if (!empty($body['todas'])) {
                $st = $pdo->query("UPDATE conversaciones SET no_leidos = 0 WHERE no_leidos > 0");
                $n = $st->rowCount();
                crm_bitacora($pdo, $operador, 'marcar_leidas_todas', "$n conversaciones");
            } else {
                $ids = $lote($body);
                if (!$ids) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare(
                    "UPDATE conversaciones SET no_leidos = 0 WHERE id IN ($ph) AND no_leidos > 0"
                );
                $st->execute($ids);
                $n = $st->rowCount();
                crm_bitacora($pdo, $operador, 'marcar_leidas', "$n de " . count($ids));
            }
            salir(['ok' => true, 'afectadas' => $n]);
        }

        if ($accion === 'archivar') {
            if (!crm_hay_archivada($pdo)) {
                salir(['ok' => false, 'error' => 'Falta correr la migración 41 en esta base.'], 409);
            }
            $ids = $lote($body);
            if (!$ids) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            // Por defecto archiva; se manda archivar:false para sacarla del archivo.
            $archivar = !array_key_exists('archivar', $body) || (bool)$body['archivar'];

            $ph = implode(',', array_fill(0, count($ids), '?'));
            /* Al archivar se dan por leidos los no_leidos. Una conversacion
               guardada que sigue contando como "sin leer" deja el globito rojo
               prendido por algo que el agente ya decidio no atender. */
            if ($archivar) {
                $st = $pdo->prepare(
                    "UPDATE conversaciones
                        SET archivada = 1, archivada_en = NOW(), archivada_por = ?, no_leidos = 0
                      WHERE id IN ($ph)"
                );
                $st->execute(array_merge([$operador], $ids));
                /* Y se baja el «Te necesita»: archivar es "sacame esto de la
                   bandeja", y una marca que sigue puesta reaparece en «Todo» y
                   en cualquier busqueda. Solo al ARCHIVAR -- desarchivar la
                   devuelve a la bandeja, pero el pedido del bot ya fue visto. */
                crm_bajar_derivada($pdo, $ids);
            } else {
                $st = $pdo->prepare(
                    "UPDATE conversaciones
                        SET archivada = 0, archivada_en = NULL, archivada_por = NULL
                      WHERE id IN ($ph)"
                );
                $st->execute($ids);
            }

            // Queda anotado: archivar esconde de la bandeja una conversacion
            // donde se hablo de plata, y esa decision tiene dueño.
            crm_bitacora($pdo, $operador,
                $archivar ? 'conversacion_archivar' : 'conversacion_desarchivar',
                'ids=' . implode(',', $ids));
            salir(['ok' => true, 'archivada' => $archivar, 'afectadas' => $st->rowCount()]);
        }

        // ---- eliminar conversaciones (una o varias; borra el hilo entero) ----
        if ($accion === 'eliminar') {
            /* SOLO ADMIN, y a proposito. Esto no esconde: destruye lo que se
               dijo. Un agente que promete algo por chat no puede ser el mismo
               que despues borra la prueba de que lo prometio.
               `mensajes` y `conversacion_agentes` se van solos por el ON
               DELETE CASCADE de sus FK (migraciones 05 y 30). */
            exigir_admin();

            $ids = $lote($body);
            if (!$ids) { salir(['ok' => false, 'error' => 'Falta id'], 400); }

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                "SELECT c.id, c.usuario, c.session_id,
                        (SELECT COUNT(*) FROM mensajes m WHERE m.conversacion_id = c.id) AS mensajes
                   FROM conversaciones c WHERE c.id IN ($ph)"
            );
            $st->execute($ids);
            $convs = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$convs) { salir(['ok' => false, 'error' => 'Esas conversaciones ya no existen'], 404); }

            /* La bitacora va ANTES del DELETE y con lo que se pierde adentro
               -- una fila POR conversacion, no un resumen del lote. Despues
               del DELETE no queda nada que consultar: si el rastro no dice a
               quien y cuantos mensajes, el borrado es invisible. */
            foreach ($convs as $conv) {
                crm_bitacora($pdo, $operador, 'conversacion_eliminar', json_encode([
                    'id'       => (int)$conv['id'],
                    'usuario'  => $conv['usuario'],
                    'session'  => $conv['session_id'],
                    'mensajes' => (int)$conv['mensajes'],
                ], JSON_UNESCAPED_UNICODE));
            }

            $del = $pdo->prepare("DELETE FROM conversaciones WHERE id IN ($ph)");
            $del->execute($ids);
            salir(['ok' => true, 'eliminadas' => count($convs)]);
        }

        /* ---- difusion a la SELECCION (mensaje de agente a varios chats) ----
           Distinto de accion=notificar: aca el destino son conversaciones
           elegidas a mano, no "todos" ni un filtro. Es el mismo camino que
           `responder`, en lote: mensaje de agente + preview + aviso push por
           chat. El jugador lo ve como una respuesta del agente, porque eso
           es. */
        if ($accion === 'difusion_seleccion') {
            $ids   = $lote($body);
            $texto = trim((string)($body['texto'] ?? ''));
            if (!$ids || $texto === '') { salir(['ok' => false, 'error' => 'Falta seleccion o texto'], 400); }
            $texto = mb_substr($texto, 0, 2000);

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, usuario FROM conversaciones WHERE id IN ($ph)");
            $st->execute($ids);
            $convs = $st->fetchAll(PDO::FETCH_ASSOC);

            $prev = $pdo->prepare(
                "UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?"
            );
            $n = 0;
            foreach ($convs as $c) {
                try {
                    crm_mensaje($pdo, (int)$c['id'], 'agente', $texto, null, $operador);
                    $prev->execute([mb_substr($texto, 0, 280), (int)$c['id']]);
                    // El aviso solo si hay a quien: un chat anonimo no tiene
                    // dispositivo asociado.
                    if ((string)$c['usuario'] !== '') {
                        notif_chat($pdo, (string)$c['usuario'], $texto, true);
                    }
                    $n++;
                } catch (Throwable $e) {
                    // Un chat que falla no frena el resto del lote.
                    error_log('difusion_seleccion conv ' . $c['id'] . ': ' . $e->getMessage());
                }
            }

            crm_bitacora($pdo, $operador, 'difusion_seleccion',
                'chats=' . $n . ' texto=' . mb_substr($texto, 0, 120));
            salir(['ok' => true, 'enviados' => $n]);
        }

        /* ---- plantillas de mensaje (guardar / borrar) ----
           En la base y no en localStorage: las comparte el equipo entero.
           Requiere sql/42_plantillas.sql; sin la migracion avisa en claro. */
        if ($accion === 'plantilla_guardar') {
            $id      = (int)($body['id'] ?? 0);
            $comando = strtolower(trim((string)($body['comando'] ?? ''), "/ \t"));
            $texto   = trim((string)($body['texto'] ?? ''));
            $atajo   = mb_substr(trim((string)($body['atajo'] ?? '')), 0, 40);
            if ($comando === '' || $texto === '') {
                salir(['ok' => false, 'error' => 'Falta el comando o el texto'], 400);
            }
            // Solo letras/numeros/guiones: el comando se tipea en el composer
            // y un espacio o una barra adentro lo haria inescribible.
            if (!preg_match('/^[a-z0-9_-]{1,30}$/', $comando)) {
                salir(['ok' => false, 'error' => 'El comando: solo letras, números y guiones (máx. 30)'], 400);
            }
            try {
                if ($id) {
                    $pdo->prepare(
                        "UPDATE plantillas_mensaje SET comando = ?, texto = ?, atajo = ? WHERE id = ?"
                    )->execute([$comando, mb_substr($texto, 0, 2000), $atajo ?: null, $id]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO plantillas_mensaje (comando, texto, atajo, creado_por) VALUES (?,?,?,?)"
                    )->execute([$comando, mb_substr($texto, 0, 2000), $atajo ?: null, $operador]);
                    $id = (int)$pdo->lastInsertId();
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    salir(['ok' => false, 'error' => "Ya existe una plantilla /$comando"], 409);
                }
                salir(['ok' => false, 'error' => 'No pude guardar (¿falta correr la migración 42?)'], 500);
            }
            salir(['ok' => true, 'id' => $id]);
        }

        if ($accion === 'plantilla_borrar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            try {
                $pdo->prepare("DELETE FROM plantillas_mensaje WHERE id = ?")->execute([$id]);
            } catch (Throwable $e) {
                salir(['ok' => false, 'error' => 'No pude borrar'], 500);
            }
            salir(['ok' => true]);
        }

        // ---- cargar fichas o bono ----
        if ($accion === 'cargar_fichas' || $accion === 'cargar_bono') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $monto   = (int)($body['monto'] ?? 0);
            $motivo  = mb_substr((string)($body['motivo'] ?? ''), 0, 200);
            $tipo    = $accion === 'cargar_bono' ? 'bono' : 'ficha';
            if ($usuario === '' || $monto === 0) {
                salir(['ok' => false, 'error' => 'Falta usuario o monto (no puede ser 0)'], 400);
            }

            $r = crm_cargar($pdo, $usuario, $tipo, $monto, $motivo, 'crm', $operador);
            if (!$r['ok']) { salir($r, 400); }

            /* Avisarle al jugador. Solo cuando es un REGALO: un monto negativo
               es un ajuste del agente y no hay nada que festejar.
               notif_crear() no lanza nunca, asi que un problema con el aviso no
               puede hacer que una carga exitosa parezca fallida. */
            if ($monto > 0) {
                [$nt, $nc] = notif_texto_carga($tipo, $monto, $motivo);
                notif_crear($pdo, $usuario, $nt, $nc, $tipo === 'bono' ? 'bono' : 'fichas', null, 'crm');
            }

            /* Un BONO positivo va AL JUEGO en el acto. El contador
               usuarios.bonus era donde los bonos iban a morir: el CRM los
               sumaba, el jugador veia el numero en el chat, y en el juego --
               donde efectivamente se juega -- nunca aparecia nada. Ahora se
               encola el deposito solo-bono (fichas_pedir_carga con monto=0):
               el mismo camino, bot y devolucion-si-falla que toda carga.
               Best-effort: si justo hay una carga en curso ('en_curso'), el
               bono QUEDA en el contador y la respuesta lo dice -- el boton
               «Bonos al juego» lo manda despues, sin volver a cargarlo. */
            $alJuego = null;
            if ($tipo === 'bono' && $monto > 0) {
                require_once __DIR__ . '/fichas_lib.php';
                try {
                    $dep = fichas_pedir_carga($pdo, $usuario, 0, 'crm', false, $monto);
                    $alJuego = !empty($dep['ok']);
                    if (!$alJuego) {
                        $r['aviso'] = ($dep['codigo'] ?? '') === 'en_curso'
                            ? 'El jugador tiene una carga en camino: el bono quedó en su contador. En un rato mandalo con «Bonos al juego».'
                            : 'El bono quedó en el contador pero no se pudo encolar al juego: ' . ($dep['error'] ?? '');
                    }
                } catch (Throwable $e) {
                    error_log('cargar_bono al juego: ' . $e->getMessage());
                    $r['aviso'] = 'El bono quedó en el contador; el envío al juego falló, usá «Bonos al juego».';
                }
            }

            // Dejar rastro en el hilo de la conversacion, si vino.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $etiqueta = $tipo === 'bono' ? 'bono' : 'fichas';
                $signo = $monto > 0 ? '+' : '';
                crm_mensaje($pdo, $convId, 'agente',
                    "Cargó $signo" . number_format($monto, 0, ',', '.') . " $etiqueta"
                    . ($tipo === 'bono' && $alJuego ? ' (va al juego)' : '')
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")->execute([$convId]);
            }
            salir(['ok' => true, 'tipo' => $tipo, 'saldo' => $r['saldo'],
                   'al_juego' => $alJuego, 'aviso' => $r['aviso'] ?? null]);
        }

        /* ---- mandar los BONOS acumulados del jugador al juego ----
           El rescate para todo bono que quedo en el contador: los cargados a
           mano cuando habia una carga en curso, los de la ruleta, los
           prometidos por notificacion de antes de que el deposito automatico
           existiera. Deposita TODO el bonus disponible en una sola carga. */
        if ($accion === 'bonos_al_juego') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            require_once __DIR__ . '/fichas_lib.php';
            $bo = $pdo->prepare("SELECT COALESCE(bonus,0) FROM usuarios WHERE username = ?");
            $bo->execute([$usuario]);
            $disp = (int)$bo->fetchColumn();
            if ($disp <= 0) { salir(['ok' => false, 'error' => 'El jugador no tiene bonos para mandar.'], 400); }
            $dep = fichas_pedir_carga($pdo, $usuario, 0, 'crm', false, $disp);
            if (empty($dep['ok'])) { salir(['ok' => false, 'error' => $dep['error'] ?? 'No se pudo encolar.'], 400); }
            crm_bitacora($pdo, $operador, 'bonos_al_juego',
                         $usuario . ': ' . $disp . ' en bonos al juego');
            salir(['ok' => true, 'monto' => (int)($dep['bono'] ?? $disp)]);
        }

        /* ---- RECAUDAR el saldo de jugadores inactivos ----
           Encola un pedido; lo ejecuta el bot del VPS (bot_recaudar.py)
           contra el panel de agentes -- el CRM no puede abrir ese panel.
           SOLO ADMIN: retira plata de cuentas de jugadores en masa, no es una
           accion de mostrador. Los topes viajan con el pedido; por defecto es
           una PRUEBA (dry_run) que lista a quien tocaria sin retirar nada. */
        // ---- campaña de fidelizacion: guardar config (solo admin) ----
        if ($accion === 'fid_guardar') {
            exigir_admin();
            require_once __DIR__ . '/fidelizacion_lib.php';
            $activa = !empty($body['activa']) ? '1' : '0';
            // La MISMA validacion que usa el motor: lo que se guarda siempre
            // parsea, y un JSON invalido se rechaza aca con detalle en vez de
            // degradar en silencio al default.
            $tramos = fid_parsear_tramos(json_encode($body['tramos'] ?? []));
            if ($tramos === null) {
                salir(['ok' => false,
                       'error' => 'Escalones inválidos: entre 1 y 10, días 1-365 sin repetir, % de 1 a 200'], 400);
            }
            cfg_crm_guardar($pdo, [
                'fid_activa' => $activa,
                'fid_tramos' => json_encode($tramos),
            ], $operador);
            crm_bitacora($pdo, $operador, 'fid_guardar',
                ($activa === '1' ? 'activa' : 'apagada') . ' · ' . count($tramos) . ' escalones: '
                . implode(', ', array_map(fn($t) => $t['dias'] . 'd=' . $t['pct'] . '%'
                    . ($t['ruleta'] ? '+giro' : ''), $tramos)));
            salir(['ok' => true, 'tramos' => $tramos, 'activa' => $activa === '1']);
        }

        if ($accion === 'recaudar_pedir') {
            exigir_admin();
            $dry  = !isset($body['si']) || !$body['si'];   // sin 'si' explicito = prueba
            $dias = max(1, (int)($body['dias'] ?? 30));
            $salt = max(0, (int)($body['saltar'] ?? 4));
            $tope = max(1, min(100, (int)($body['tope'] ?? 10)));
            $minS = max(0, (int)($body['min_saldo'] ?? 100));

            // Una sola en vuelo: si ya hay pendiente/procesando, no apilar otra
            // (el bot las hace de a una; dos pedidos encimados confunden).
            $enVuelo = $pdo->query(
                "SELECT id FROM recaudaciones WHERE estado IN ('pendiente','procesando') LIMIT 1"
            )->fetchColumn();
            if ($enVuelo) {
                salir(['ok' => false, 'error' => 'Ya hay una recaudación en curso (#'
                        . (int)$enVuelo . '). Esperá a que termine.'], 409);
            }

            $pdo->prepare(
                "INSERT INTO recaudaciones (estado, dry_run, dias, saltar, tope, min_saldo, pedido_por)
                 VALUES ('pendiente', ?, ?, ?, ?, ?, ?)"
            )->execute([$dry ? 1 : 0, $dias, $salt, $tope, $minS, $operador]);
            $id = (int)$pdo->lastInsertId();
            crm_bitacora($pdo, $operador, 'recaudar_pedir',
                "#$id " . ($dry ? 'PRUEBA' : 'REAL') . " dias=$dias saltar=$salt tope=$tope min=$minS");
            salir(['ok' => true, 'id' => $id, 'dry_run' => $dry]);
        }

        // ---- cargar / retirar SALDO real (se encola para el worker de ganamos) ----
        if ($accion === 'cargar_saldo' || $accion === 'retirar_saldo') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $monto   = (float)($body['monto'] ?? 0);
            $motivo  = mb_substr((string)($body['motivo'] ?? ''), 0, 200);
            $tipo    = $accion === 'retirar_saldo' ? 'retirar' : 'cargar';

            /* =============================================================
               RETIRO A MANO CON UN PEDIDO ABIERTO: NO SE HACE A CIEGAS.

               EL CASO (Nahuel, 18/09/2026): *"si una persona tiene cien mil
               fichas y solicita un retiro de veinte mil, un operador puede
               hacerle ese retiro a mano. Pero si ese jugador previamente hizo
               una solicitud desde el boton de retiros, esa solicitud queda
               activa y viene otro empleado y le vuelve a retirar otras 20.000
               cuando la apruebe"*.

               Ya habia un aviso en el modal desde el 15/09, y no alcanza: es
               un texto que se lee una vez, y el que paga dos veces es el
               SEGUNDO operador, que nunca lo vio. La proteccion tiene que
               estar acá, del lado del server, donde pasan los dos.

               Por eso el retiro manual EXIGE decir qué se hace con lo que
               estaba abierto. Sin `confirmado`, se rechaza con la lista para
               que el cliente la muestre. No es burocracia: es la unica forma
               de que quede una decision explicita antes de sacar plata dos
               veces sobre el mismo saldo.

               Es solo para RETIRAR. Cargar de mas no tiene esta simetria: dos
               cargas son dos cargas, y el jugador no pierde nada.
               ============================================================= */
            if ($tipo === 'retirar') {
                $abiertos = [];
                try {
                    $sa = $pdo->prepare(
                        "SELECT id, monto, estado FROM acciones_saldo
                          WHERE usuario = ? AND tipo = 'retirar'
                            AND estado IN ('pendiente','procesando','revisar','error')
                          ORDER BY creada_en DESC LIMIT 5"
                    );
                    $sa->execute([$usuario]);
                    foreach ($sa->fetchAll(PDO::FETCH_ASSOC) as $f) {
                        $abiertos[] = ['id' => (int)$f['id'], 'monto' => (float)$f['monto'],
                                       'estado' => (string)$f['estado'], 'del_juego' => false];
                    }
                } catch (Throwable $e) { error_log('retirar/abiertos: ' . $e->getMessage()); }
                try {
                    $sp = $pdo->prepare(
                        "SELECT monto FROM retiros_panel
                          WHERE username = ? AND estado = 'abierto' LIMIT 5"
                    );
                    $sp->execute([$usuario]);
                    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $f) {
                        $abiertos[] = ['id' => 0, 'monto' => (float)$f['monto'],
                                       'estado' => 'pendiente', 'del_juego' => true];
                    }
                } catch (Throwable $e) { /* sin migracion 64 no hay espejo */ }

                if ($abiertos && empty($body['confirmado'])) {
                    salir(['ok' => false, 'codigo' => 'retiros_abiertos',
                           'abiertos' => $abiertos,
                           'error' => 'Este jugador ya tiene un pedido de retiro abierto. '
                                    . 'Confirmá qué hacer con él antes de retirarle a mano.'], 409);
                }

                /* CANCELAR LOS QUE EL OPERADOR ELIGIO. Va ANTES de crear el
                   retiro nuevo: si algo falla despues, lo peor que queda es un
                   pedido cancelado de mas -- visible y reversible. Al reves
                   quedaria el retiro hecho Y el pedido vivo, que es justo el
                   doble pago que esto viene a evitar.

                   Solo `pendiente`: un `procesando` ya lo tiene el worker y
                   cerrarlo en la base no lo frena en el panel. */
                $aCancelar = $body['cancelar'] ?? [];
                if (is_array($aCancelar) && $aCancelar) {
                    $cancel = $pdo->prepare(
                        "UPDATE acciones_saldo
                            SET estado = 'cancelada',
                                mensaje = CONCAT(COALESCE(mensaje,''),
                                          ' | cancelado por ', ?, ' al retirar a mano desde la ficha')
                          WHERE id = ? AND usuario = ? AND tipo = 'retirar'
                            AND estado = 'pendiente'"
                    );
                    foreach ($aCancelar as $cid) {
                        $cid = (int)$cid;
                        if ($cid <= 0) { continue; }
                        $cancel->execute([mb_substr((string)$operador, 0, 60), $cid, $usuario]);
                        if ($cancel->rowCount() > 0) {
                            crm_bitacora($pdo, $operador, 'cancelar_retiro',
                                         "id $cid (al retirar a mano de @$usuario)");
                        }
                    }
                }
            }

            $r = crm_saldo($pdo, $usuario, $tipo, $monto, $motivo, $operador);
            if (!$r['ok']) { salir($r, 400); }

            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $verbo = $tipo === 'retirar' ? 'Retiro de' : 'Carga de';
                crm_mensaje($pdo, $convId, 'agente',
                    "$verbo $" . number_format($monto, 0, ',', '.') . " de saldo (pendiente en ganamos)"
                    . ($motivo !== '' ? " · $motivo" : ''), ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")->execute([$convId]);
            }
            salir(['ok' => true, 'encolada' => true]);
        }

        /* ---- eliminar un mensaje ENVIADO (estilo WhatsApp) ----
           Borrado BLANDO (migracion 58): la fila queda con borrado_en y hace
           de rastro en el CRM ("Mensaje eliminado"), de freno de entrega
           (mis_mensajes deja de mandarlo) y de LAPIDA para retraerlo del
           widget del jugador que ya lo habia recibido.
           SOLO salientes: los mensajes del jugador son SU palabra en un
           sistema que mueve plata -- no se tocan, ni por error.
           OJO: va en el bloque POST (el boton postea). El primer intento
           quedo en el bloque GET y el server contestaba "accion desconocida". */
        if ($accion === 'mensaje_borrar') {
            $mid = (int)($body['id'] ?? 0);
            if (!$mid) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            try {
                $st = $pdo->prepare(
                    "UPDATE mensajes SET borrado_en = NOW(), borrado_por = ?
                      WHERE id = ? AND rol <> 'user' AND borrado_en IS NULL"
                );
                $st->execute([mb_substr((string)$operador, 0, 60), $mid]);
            } catch (Throwable $e) {
                salir(['ok' => false, 'error' => 'Falta la migración 58 (mensajes.borrado_en)'], 500);
            }
            if ($st->rowCount() === 0) {
                salir(['ok' => false,
                       'error' => 'Ese mensaje no se puede eliminar (no existe, ya está eliminado, o es del jugador)'], 400);
            }
            crm_bitacora($pdo, $operador, 'mensaje_borrar', 'mensaje #' . $mid);
            salir(['ok' => true, 'id' => $mid]);
        }

        // ---- responder al cliente (mensaje del agente) ----
        if ($accion === 'responder') {
            $id = (int)($body['id'] ?? 0);
            $texto = trim((string)($body['texto'] ?? ''));
            if (!$id || $texto === '') { salir(['ok' => false, 'error' => 'Falta id o texto'], 400); }
            crm_mensaje($pdo, $id, 'agente', mb_substr($texto, 0, 2000), null, $operador);
            // Responder = atender: si el agente aún no estaba asignado a este
            // chat, queda asignado solo (aparece su etiqueta sin tener que
            // tocar "Atender" a mano).
            crm_agente_tomar($pdo, $id, $operador);
            $pdo->prepare("UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?")
                ->execute([mb_substr($texto, 0, 280), $id]);

            /* Atendida: se apaga la marca de derivada (migracion 49).
               Es lo que cierra el circuito -- si no se limpiara, la
               conversacion quedaria destacada arriba de la bandeja para
               siempre y el badge del rail nunca bajaria de ahi, o sea que en
               dos dias nadie le daria bola a ese numero.
               Solo la marca: `ia_activa` NO se vuelve a prender sola. El bot se
               corrio porque habia un problema que el no podia resolver; que
               vuelva a hablar es una decision del agente, con su switch. */
            crm_bajar_derivada($pdo, [$id]);

            /* ACA se calla el bot, y no al derivar: recien ahora hay una persona
               del otro lado. Derivar solo avisa; mientras el agente no aparece,
               el bot sigue resolviendo (una carga, el alias, el saldo).
               ia_silencio_en (migracion 60) deja la marca de que se callo SOLO,
               que es lo que despues permite despertarlo si el agente no vuelve a
               escribir en ia_reconectar_min. Antes se apagaba al derivar y el
               despertar dependia de derivada_en, que estas mismas lineas de
               arriba borran: por eso quedaba mudo para siempre. */
            try {
                $pdo->prepare(
                    "UPDATE conversaciones SET ia_activa = 0, ia_silencio_en = NOW() WHERE id = ?"
                )->execute([$id]);
            } catch (Throwable $e) {
                // Sin la migracion 60 no existe la columna: al menos se calla.
                try {
                    $pdo->prepare("UPDATE conversaciones SET ia_activa = 0 WHERE id = ?")->execute([$id]);
                } catch (Throwable $e2) { error_log('responder/ia_activa: ' . $e2->getMessage()); }
            }

            /* La respuesta del agente llega cuando llega: es el caso donde mas
               falta hace el aviso, porque el jugador casi nunca sigue mirando.
               El que esta adentro no lo ve dos veces: ya lo recibe por
               mis_mensajes.php y el widget consume el aviso sin dibujarlo. */
            $st = $pdo->prepare("SELECT usuario FROM conversaciones WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $destino = (string)($st->fetchColumn() ?: '');
            if ($destino !== '') { notif_chat($pdo, $destino, $texto, true); }

            salir(['ok' => true]);
        }

        /* ---- difusión: push, mensaje de chat, o ambos ----
           `todos: true` manda a todos los celulares/conversaciones; si no, va
           al jugador indicado. El push es UNA fila aunque vaya a mil (la
           entrega la resuelve notificaciones_entregas al sondear); el chat
           masivo SÍ inserta un mensaje por conversación (ver
           crm_difusion_chat_aplicar), porque cada uno vive en su propio hilo. */
        if ($accion === 'notificar') {
            $todos   = !empty($body['todos']);
            $usuario = $todos ? null : trim((string)($body['usuario'] ?? ''));

            // Filtro de audiencia (Fase notificaciones avanzadas): si viene
            // filtro.modo="inactivos", el push NO va a "todos" ni a un
            // usuario puntual -- se resuelve la lista de inactivos y se
            // manda por crmnotif_enviar_masivo (una fila por destinatario,
            // agrupadas por lote_id). El canal chat/programación siguen el
            // camino de siempre para el resto de los modos.
            $filtro = is_array($body['filtro'] ?? null) ? $body['filtro'] : null;
            $modoFiltro = $filtro ? (string)($filtro['modo'] ?? '') : '';

            $canal = (string)($body['canal'] ?? 'push');
            if (!in_array($canal, ['push', 'chat', 'ambos'], true)) { $canal = 'push'; }
            $incluyePush = $canal === 'push' || $canal === 'ambos';
            $incluyeChat = $canal === 'chat' || $canal === 'ambos';

            $titulo = trim((string)($body['titulo'] ?? ''));
            $cuerpo = trim((string)($body['cuerpo'] ?? ''));
            // Para el chat, si no mandaron un texto propio, se usa el cuerpo
            // del push (evita pedir lo mismo dos veces cuando el canal es "ambos").
            $mensajeChat = trim((string)($body['mensaje_chat'] ?? '')) ?: $cuerpo;

            /* La programación se parsea ACA ARRIBA, antes de las ramas por
               filtro. Antes vivia mas abajo y las ramas de inactivos/sin_chat
               salian antes de leerla: el operador programaba una difusion
               para las 19:00, el confirm le mostraba la fecha, y SE MANDABA
               YA, sin ningun aviso. */
            $progEn = null;
            $progRaw = trim((string)($body['programada_en'] ?? ''));
            if ($progRaw !== '') {
                $progEn = crm_parse_programada($progRaw);
                if ($progEn === null) {
                    salir(['ok' => false, 'error' => 'Fecha/hora de programación inválida'], 400);
                }
            }

            if ($modoFiltro === 'inactivos') {
                /* Solo push, y RECHAZANDO el resto: antes canal 'chat' llegaba
                   aca, se respondia ok y no se mandaba nada util. */
                if ($incluyeChat) {
                    salir(['ok' => false, 'error' =>
                        'La difusión a inactivos va solo por push (todavía no hay chat masivo para este filtro)'], 400);
                }
                if ($titulo === '' || $cuerpo === '') {
                    salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
                }
                // Piso 1: "hace mas de 0 dias" era literalmente TODOS, un
                // duplicado confuso del destino "Todos".
                $dias = max(1, (int)($filtro['dias'] ?? 1));
                $r = crmnotif_enviar_masivo($pdo, ['modo' => 'inactivos', 'dias' => $dias],
                                            $titulo, $cuerpo, (string)($body['tipo'] ?? 'promo'),
                                            'difusion', $operador, $progEn);
                if (!$r['ok']) { salir($r, 500); }
                salir(['ok' => true, 'alcance' => $r['alcance'], 'lote_id' => $r['lote_id'],
                       'canal' => 'push', 'programada_en' => $progEn]);
            }

            /* ---- los que nunca escribieron ----
               El unico destinatario que NO sale de `conversaciones`: sale de
               `usuarios`. Por eso no puede pasar por crm_difusion_chat_aplicar,
               que recorre los chats que ya hay y a estos los saltea en silencio
               (devolvia 0 sin decir por que).
               Push aparte: para recibirlo hace falta un dispositivo registrado,
               y alguien creado en el panel que nunca abrio nada no tiene
               ninguno. Se manda igual si lo pidieron -- si tiene la app, le
               llega -- pero el que de verdad alcanza a este grupo es el chat. */
            if ($modoFiltro === 'sin_chat') {
                if ($incluyeChat && $mensajeChat === '') {
                    salir(['ok' => false, 'error' => 'Falta el mensaje del chat'], 400);
                }
                if ($incluyePush && ($titulo === '' || $cuerpo === '')) {
                    salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
                }
                /* La SIEMBRA de chats crea conversaciones en el acto y no
                   tiene cola: programarla no esta soportado. Antes se
                   aceptaba la fecha y se sembraba YA, en silencio. */
                if ($incluyeChat && $progEn) {
                    salir(['ok' => false, 'error' =>
                        'La siembra de chats no se puede programar: mandala ahora, o programá solo el push'], 400);
                }
                $destinos = crmnotif_usuarios_sin_chat($pdo);
                if (!$destinos) {
                    salir(['ok' => true, 'alcance' => 0, 'canal' => 'chat',
                           'aviso' => 'No hay jugadores sin chat: todos escribieron alguna vez.']);
                }

                $alcanceChat = 0;
                if ($incluyeChat) {
                    $alcanceChat = crm_difusion_chat_sembrar($pdo, $destinos, $mensajeChat, $operador);
                }
                $alcancePush = 0;
                if ($incluyePush) {
                    /* Agrupado por lote, como inactivos: sin esto el historial
                       mostraba cientos de envios sueltos "@usuario" en vez de
                       UNA difusion. */
                    $loteId = crmnotif_uuid();
                    $filtroJson = json_encode(['modo' => 'sin_chat'], JSON_UNESCAPED_UNICODE);
                    foreach ($destinos as $d) {
                        $nid = notif_crear($pdo, $d, $titulo, $cuerpo,
                                           (string)($body['tipo'] ?? 'promo'), null, 'difusion',
                                           null, false, $progEn);
                        if ($nid) {
                            crmnotif_marcar_lote($pdo, $nid, $loteId, $filtroJson);
                            $alcancePush++;
                        }
                    }
                }
                crm_bitacora($pdo, $operador, 'difusion_sin_chat',
                             'A ' . count($destinos) . ' jugador(es) sin chat'
                             . ($progEn ? " (push programado $progEn)" : '') . ': '
                             . mb_substr($mensajeChat ?: $cuerpo, 0, 120));
                salir(['ok' => true, 'alcance' => $incluyeChat ? $alcanceChat : $alcancePush,
                       'alcance_chat' => $alcanceChat, 'alcance_push' => $alcancePush,
                       'canal' => $incluyeChat ? 'chat' : 'push', 'programada_en' => $progEn]);
            }

            if (!$todos && $usuario === '') {
                salir(['ok' => false, 'error' => 'Elegí un jugador o marcá "a todos"'], 400);
            }
            if ($incluyePush && ($titulo === '' || $cuerpo === '')) {
                salir(['ok' => false, 'error' => 'Falta el título o el mensaje'], 400);
            }
            if ($incluyeChat && $mensajeChat === '') {
                salir(['ok' => false, 'error' => 'Falta el mensaje del chat'], 400);
            }
            if (!$todos) {
                $st = $pdo->prepare("SELECT 1 FROM usuarios WHERE username = ? LIMIT 1");
                $st->execute([$usuario]);
                if (!$st->fetchColumn()) {
                    salir(['ok' => false, 'error' => 'Ese usuario no existe'], 400);
                }
            }

            // ($progEn ya viene parseado arriba, antes de las ramas por filtro.)
            $pushId = null;
            if ($incluyePush) {
                /* Origen 'difusion': lo que manda un OPERADOR desde esta vista,
                   distinguible de los avisos automaticos del CRM (que tambien
                   usan 'crm') -- es lo que hace funcionar el filtro "Solo
                   difusiones" del historial. */
                $pushId = notif_crear($pdo, $usuario, $titulo, $cuerpo,
                                      (string)($body['tipo'] ?? 'promo'), null, 'difusion', null, false, $progEn);
                if (!$pushId) {
                    $err = $progEn
                        ? 'No se pudo programar el push (¿falta correr la migración 29_notif_programada.sql?)'
                        : 'No se pudo encolar el push';
                    salir(['ok' => false, 'error' => $err], 500);
                }
            }

            $chatAlcance = null;
            if ($incluyeChat) {
                if ($progEn) {
                    // Programado: se encola, un cron lo aplica en el momento
                    // (ver difusiones_chat_procesar.php) -- insertarlo ya
                    // mismo lo mostraría antes de tiempo en el chat.
                    try {
                        $pdo->prepare(
                            "INSERT INTO difusiones_chat (usuario, texto, programada_en, creado_por) VALUES (?,?,?,?)"
                        )->execute([$usuario, $mensajeChat, $progEn, $operador]);
                    } catch (Throwable $e) {
                        salir(['ok' => false, 'error' => 'No se pudo programar el chat (¿falta correr la migración 32_difusiones_chat.sql?)'], 500);
                    }
                } else {
                    // Ahora mismo: se aplica en la misma request.
                    $chatAlcance = crm_difusion_chat_aplicar($pdo, $usuario, $mensajeChat);
                }
            }

            // Rastro en el hilo, para que despues se entienda por que escribio.
            $convId = (int)($body['conversacion_id'] ?? 0);
            if ($convId) {
                $partes = [];
                if ($incluyePush) { $partes[] = $progEn ? "programó un push ($progEn)" : "envió un push"; }
                if ($incluyeChat) { $partes[] = $progEn ? "programó un mensaje de chat ($progEn)" : "envió un mensaje de chat"; }
                $rastro = ucfirst(implode(' y ', $partes)) . ": " . ($titulo ?: $mensajeChat);
                crm_mensaje($pdo, $convId, 'agente', $rastro, ['interno' => true], $operador);
                $pdo->prepare("UPDATE conversaciones SET actualizada_en = NOW() WHERE id = ?")
                    ->execute([$convId]);
            }

            /* El alcance del push es informativo: celulares ACTIVOS que la
               van a recibir (puede ser 0 y estar todo bien). El del chat es
               real: conversaciones donde efectivamente se insertó el mensaje
               (null si quedó programado, todavía no se sabe). */
            salir(['ok' => true, 'id' => $pushId, 'programada_en' => $progEn, 'canal' => $canal,
                   'alcance' => $incluyePush ? notif_alcance($pdo, $usuario) : null,
                   'chat_alcance' => $chatAlcance]);
        }

        // ---- presets de filtro (guardar/editar reusan el mismo UPSERT) ----
        if ($accion === 'notif_preset_guardar') {
            $nombre = trim((string)($body['nombre'] ?? ''));
            $filtro = is_array($body['filtro'] ?? null) ? $body['filtro'] : [];
            $r = crmnotif_preset_guardar($pdo, $nombre, $filtro, $operador);
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'notif_preset_borrar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = crmnotif_preset_borrar($pdo, $id);
            salir(['ok' => $ok], $ok ? 200 : 400);
        }

        // ---- bonos pendientes (prometidos por notificación) ----
        if ($accion === 'bono_crear') {
            $usuario = trim((string)($body['usuario'] ?? ''));
            $tipo    = (string)($body['tipo'] ?? '');
            $valor   = (int)($body['valor'] ?? 0);
            $r = crmnotif_bono_crear($pdo, $usuario, $tipo, $valor, $operador,
                                     isset($body['notificacion_id']) ? (int)$body['notificacion_id'] : null);
            if ($r['ok']) {
                crm_bitacora($pdo, $operador, 'bono_crear', "$usuario · $tipo · $valor");
            }
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'bono_editar') {
            $id    = (int)($body['id'] ?? 0);
            $tipo  = (string)($body['tipo'] ?? '');
            $valor = (int)($body['valor'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $r = crmnotif_bono_editar($pdo, $id, $tipo, $valor);
            salir($r, $r['ok'] ? 200 : 400);
        }
        if ($accion === 'bono_borrar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = crmnotif_bono_borrar($pdo, $id);
            if ($ok) { crm_bitacora($pdo, $operador, 'bono_borrar', "id=$id"); }
            salir(['ok' => $ok], $ok ? 200 : 400);
        }

        // ---- anclar / desanclar ----
        if ($accion === 'fijar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $fijar = !empty($body['fijar']) ? 1 : 0;
            $pdo->prepare("UPDATE conversaciones SET fijada = ? WHERE id = ?")->execute([$fijar, $id]);
            salir(['ok' => true, 'fijada' => (bool)$fijar]);
        }

        // ---- atender / soltar un chat (asignación de agente, relevo) ----
        /* SACAR EL «Te necesita» SIN TENER QUE RESPONDER.
           Hasta ahora esa marca se bajaba sola de tres formas --responder,
           atender o cerrar la conversacion-- y ninguna sirve cuando el pedido
           ya se resolvio POR AFUERA: lo llamaste por telefono, se resolvio solo,
           o el jugador escribio de nuevo y ya no necesita nada. Quedaba clavada
           arriba de la bandeja y sumando al badge para siempre, que es como ese
           numero deja de significar algo.
           Va con su linea en la bitacora: es un aviso que alguien decide
           apagar, no un click sin consecuencia. */
        if ($accion === 'quitar_derivada') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $n = crm_bajar_derivada($pdo, [$id]);
            if ($n > 0) { crm_bitacora($pdo, $operador, 'quitar_derivada', "conv $id"); }
            salir(['ok' => true, 'bajadas' => $n]);
        }

        if ($accion === 'atender' || $accion === 'soltar') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            // El agente que atiende/suelta es SIEMPRE el logueado, nunca uno que
            // venga por el body: así nadie asigna/desasigna en nombre de otro.
            if ($accion === 'atender') {
                crm_agente_tomar($pdo, $id, $operador);
                /* Atender ES la respuesta al «Te necesita»: el bot pidio una
                   persona y la persona aparecio. Sin esto, el unico camino que
                   bajaba la marca era responder, asi que tomar el chat no
                   cambiaba nada en la bandeja ni en el badge.
                   `soltar` NO la vuelve a subir: el pedido del bot ya fue
                   atendido; si hace falta de nuevo, lo vuelve a derivar el. */
                crm_bajar_derivada($pdo, [$id]);
            } else {
                crm_agente_soltar($pdo, $id, $operador);
            }
            salir(['ok' => true, 'agentes' => crm_agentes_de($pdo, $id)]);
        }

        // ---- prender/apagar la IA para UN chat puntual ----
        if ($accion === 'chatbot_ia_chat') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $activa = !empty($body['activa']) ? 1 : 0;
            /* Se limpia ia_silencio_en en las dos direcciones: apagar A MANO no
               deja marca, asi que el bot NO se despierta solo (es una decision
               del operador y se respeta); y al prenderlo tampoco queda una marca
               vieja que lo vuelva a dormir. */
            try {
                $pdo->prepare("UPDATE conversaciones SET ia_silencio_en = NULL WHERE id = ?")->execute([$id]);
            } catch (Throwable $e) { /* sin migracion 60 */ }
            $pdo->prepare("UPDATE conversaciones SET ia_activa = ? WHERE id = ?")
                ->execute([$activa, $id]);
            salir(['ok' => true, 'ia_activa' => (bool)$activa]);
        }

        // ---- guardar el contexto DINAMICO del chatbot (+ on/off) ----
        if ($accion === 'chatbot_guardar') {
            $activo = !empty($body['activo']) ? 1 : 0;
            crm_chatbot_guardar($pdo, [
                'bot_nombre'   => $body['bot_nombre']   ?? '',
                'bot_tono'     => $body['bot_tono']     ?? '',
                'reglas_extra' => $body['reglas_extra'] ?? '',
            ], $activo);
            salir(['ok' => true, 'activo' => (bool)$activo]);
        }

        // ---- ajustes del sitio (vista Configuracion) ----
        //
        // Solo admin: apagar la ruleta o el registro cambia lo que ve TODO el
        // sitio, no una conversacion. Un agente de mostrador no deberia poder
        // hacerlo sin querer.
        /* ---- Plan de referidos ----
           La config (activo, monto, plantilla) viaja por config/config_guardar
           como cualquier otro ajuste. Aca va lo que la config generica no
           sabe hacer: los numeros del plan y la difusion PERSONALIZADA.

           La difusion del plan NO reusa la masiva comun a proposito: aquella
           manda EL MISMO texto a todos, y aca cada cliente tiene que recibir
           SU link, con su codigo adentro -- es lo que permite saber despues
           quien trajo a quien. {link} en la plantilla se reemplaza por
           persona. */
        if ($accion === 'referidos_resumen') {
            if (!function_exists('ref_resumen')) {
                $f = __DIR__ . '/referidos_lib.php';
                if (is_file($f)) { require_once $f; }
            }
            if (!function_exists('ref_resumen')) {
                salir(['ok' => false, 'error' => 'Falta api/referidos_lib.php en el server.'], 500);
            }
            // El link de muestra sale con un codigo de ejemplo: el agente ve
            // la forma exacta de lo que va a recibir cada cliente.
            salir(['ok' => true, 'resumen' => ref_resumen($pdo),
                   'link_muestra' => ref_link('a1b2c3d4')]);
        }

        if ($accion === 'referidos_difundir') {
            exigir_admin();   // manda mensajes a toda la base: decision de dueño
            if (!function_exists('ref_codigo_de')) {
                $f = __DIR__ . '/referidos_lib.php';
                if (is_file($f)) { require_once $f; }
            }
            if (!function_exists('ref_codigo_de')) {
                salir(['ok' => false, 'error' => 'Falta api/referidos_lib.php en el server.'], 500);
            }
            if (!cfg_crm_activo($pdo, 'ref_activo')) {
                salir(['ok' => false, 'error' => 'El plan está apagado. Activalo y guardá antes de difundir.'], 400);
            }
            /* El link lo pone EL SISTEMA, siempre. El operador escribe el
               texto (o ni eso: hay un default), y si no puso {link} para
               elegir donde va, se agrega solo al final. Antes esto era un
               error 400 que obligaba a conocer el placeholder -- exigirle
               sintaxis a un campo de marketing es como perder jugadores,
               pero en agente. */
            $plantilla = trim((string)cfg_crm($pdo, 'ref_mensaje'));
            if ($plantilla === '') {
                // Solo si el operador BORRO la plantilla a proposito: el
                // default con texto vive en config_crm y cfg_crm lo devuelve
                // solo cuando la fila no existe.
                $plantilla = '🎁 ¡Invitá y ganá! Compartí tu link con tus amigos: cuando '
                    . 'uno se registre y haga su primera carga, te regalamos {bono} en bonos.';
            }
            if (strpos($plantilla, '{link}') === false) {
                $plantilla .= "\n\n{link}";
            }
            /* {bono} = el monto configurado, con puntos de miles. Se resuelve
               UNA vez aca (es igual para todos); {link} adentro del loop (es
               de cada uno). Con el monto en 0 se degrada a "bonos" a secas
               antes que difundir un "0 en bonos". */
            $monto = (int)round((float)cfg_crm($pdo, 'ref_bono_monto'));
            $plantilla = str_replace(
                '{bono}',
                $monto > 0 ? number_format($monto, 0, ',', '.') : 'unos',
                $plantilla
            );

            /* A quien: todos los chats con usuario. Con incluir_sin_chat,
               tambien los clientes que nunca escribieron (se les crea la
               conversacion, como en la difusion "sin chat" comun). */
            $destinos = $pdo->query(
                "SELECT DISTINCT usuario FROM conversaciones
                  WHERE usuario IS NOT NULL AND usuario <> ''"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($body['incluir_sin_chat']) && function_exists('crmnotif_usuarios_sin_chat')) {
                $destinos = array_values(array_unique(array_merge(
                    $destinos, crmnotif_usuarios_sin_chat($pdo)
                )));
            }

            $enviados = 0; $sinCodigo = 0;
            foreach ($destinos as $u) {
                $u = trim((string)$u);
                if ($u === '') { continue; }
                $cod = ref_codigo_de($pdo, $u);
                if ($cod === '') { $sinCodigo++; continue; }   // tabla sin migrar: se corta solo
                $texto = str_replace('{link}', ref_link($cod), $plantilla);
                try {
                    $convId = crm_conversacion_id($pdo, 'crm:' . mb_substr($u, 0, 50), $u);
                    crm_mensaje($pdo, $convId, 'agente', $texto, null, $operador);
                    $pdo->prepare(
                        "UPDATE conversaciones SET preview = ?, actualizada_en = NOW() WHERE id = ?"
                    )->execute([mb_substr($texto, 0, 280), $convId]);
                    $enviados++;
                } catch (Throwable $e) {
                    error_log('referidos_difundir ' . $u . ': ' . $e->getMessage());
                }
            }
            crm_bitacora($pdo, $operador, 'referidos_difundir',
                         'Plan de referidos a ' . $enviados . ' cliente(s)');
            salir(['ok' => true, 'enviados' => $enviados, 'sin_codigo' => $sinCodigo]);
        }

        if ($accion === 'config_guardar') {
            exigir_admin();
            $vals = is_array($body['config'] ?? null) ? $body['config'] : [];
            // cfg_crm_guardar ignora las claves que no conoce, asi que no hace
            // falta filtrar aca: lo que no este en CFG_CRM_DEFAULTS no entra.
            $n = cfg_crm_guardar($pdo, $vals, $operador);
            salir(['ok' => true, 'guardados' => $n, 'config' => cfg_crm_todo($pdo)]);
        }

        /* ---- asistente de Telegram: validar, detectar el chat y probar ----
           Existe para que configurar esto no sea "abri esta URL con el token
           adentro y busca un numero en un JSON", que es la unica forma que
           documenta Telegram y donde se traba todo el mundo. Un boton.

           Es solo-admin y manda un mensaje real: no lo dejes sin exigir_admin,
           porque con el token de otro serviria para spamear su grupo. */
        if ($accion === 'tg_probar') {
            exigir_admin();
            if (!function_exists('tg_llamar')) {
                salir(['ok' => false, 'error' => 'Falta api/telegram_lib.php en el server.'], 500);
            }
            // Token del formulario si lo mandaron (asi se puede probar ANTES de
            // guardar); si no, el que ya este configurado.
            $token = trim((string)($body['token'] ?? ''));
            $chat  = trim((string)($body['chat_id'] ?? ''));
            if ($token === '') {
                $cred  = tg_credenciales($pdo);
                $token = (string)($cred['token'] ?? '');
                if ($chat === '') { $chat = (string)($cred['chat'] ?? ''); }
            }
            if ($token === '') {
                salir(['ok' => false, 'error' => 'Falta el token del bot. Pedíselo a @BotFather.'], 422);
            }

            // 1) ¿El token sirve? getMe es la forma barata de saberlo, y de
            //    paso devuelve el @usuario del bot para mostrarlo.
            $yo = tg_llamar($token, 'getMe');
            if (empty($yo['ok'])) {
                salir(['ok' => false, 'paso' => 'token', 'error' =>
                    'El token no sirve: ' . (string)($yo['description'] ?? $yo['error'] ?? 'Telegram lo rechazo.')], 422);
            }
            $bot = (string)($yo['result']['username'] ?? '');

            /* 2) A quien avisarle. Se detecta si no hay nada cargado, o si lo
                  piden expresamente con `redetectar`.

                  Ese segundo caso hacia falta: al pasar de un chat personal a un
                  GRUPO, el campo ya tenia el id viejo, asi que "Probar" mandaba
                  el mensaje al chat de siempre y no buscaba nunca el nuevo. El
                  sintoma era exactamente "funciona, pero me sigue escribiendo
                  solo a mi" y no habia forma de salir de ahi sin vaciar el campo
                  a mano y guardar. */
            $detectado = null;
            $chats     = [];
            if ($chat === '' || !empty($body['redetectar'])) {
                $d = tg_detectar_chat($token);
                if (empty($d['ok'])) {
                    salir(['ok' => false, 'paso' => 'chat', 'bot' => $bot,
                           'error' => (string)$d['error']], 422);
                }
                $chats     = (array)($d['chats'] ?? []);
                /* Con varios detectados se prefiere un GRUPO (id negativo): si
                   alguien acaba de agregar el bot a un grupo, es a donde quiere
                   que vaya el aviso -- su chat personal ya estaba de antes, de
                   cuando probo. Igual se devuelve la lista entera para elegir. */
                $grupos    = array_values(array_filter($chats,
                    static fn($c) => str_starts_with((string)($c['id'] ?? ''), '-')));
                $detectado = $grupos ? end($grupos) : ($d['sugerido'] ?? null);
                $chat      = (string)($detectado['id'] ?? '');
            }
            if ($chat === '') {
                salir(['ok' => false, 'paso' => 'chat', 'bot' => $bot,
                       'error' => 'No encontré a quién avisarle.'], 422);
            }

            // 3) El mensaje de prueba. Es la unica confirmacion que vale: que
            //    le SUENE el celular, no que la API conteste 200.
            $env = tg_llamar($token, 'sendMessage', [
                'chat_id'    => $chat,
                'text'       => "✅ <b>Listo</b>\nDesde ahora te aviso acá cuando el bot "
                              . "derive una conversación a un agente.",
                'parse_mode' => 'HTML',
            ]);
            if (empty($env['ok'])) {
                salir(['ok' => false, 'paso' => 'envio', 'bot' => $bot, 'chat_id' => $chat,
                       'error' => 'No pude mandarle el mensaje: '
                                . (string)($env['description'] ?? $env['error'] ?? '')
                                . '. Si es un grupo, agregá el bot al grupo primero.'], 422);
            }

            salir(['ok' => true, 'bot' => $bot, 'chat_id' => $chat,
                   'detectado' => $detectado, 'chats' => $chats]);
        }

        // ---- crear un agente humano / resetear su clave (solo admin) ----
        if ($accion === 'agente_crear') {
            exigir_admin();
            $r = crm_agente_crear($pdo, trim((string)($body['usuario'] ?? '')),
                                  (string)($body['password'] ?? ''));
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- activar / desactivar un operador (solo admin) ----
        if ($accion === 'agente_estado') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            $activo  = !empty($body['activo']) ? 1 : 0;
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_estado($pdo, $usuario, $activo, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- editar rol (y opcionalmente resetear clave) de un operador ----
        if ($accion === 'agente_editar') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            $rol     = trim((string)($body['rol'] ?? ''));
            $pass    = array_key_exists('password', $body) ? (string)$body['password'] : null;
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_editar($pdo, $usuario, $rol, $pass, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- eliminar un operador ----
        if ($accion === 'agente_eliminar') {
            exigir_admin();
            $usuario = trim((string)($body['usuario'] ?? ''));
            if ($usuario === '') { salir(['ok' => false, 'error' => 'Falta usuario'], 400); }
            $r = crm_agente_eliminar($pdo, $usuario, $operador);
            salir($r, $r['ok'] ? 200 : 422);
        }

        // ---- cancelar una difusión programada antes de que salga ----
        if ($accion === 'programada_cancelar') {
            $id    = (int)($body['id'] ?? 0);
            $canal = (string)($body['canal'] ?? 'push');
            if (!$id) { salir(['ok' => false, 'error' => 'Falta id'], 400); }
            $ok = $canal === 'chat' ? crm_difusion_chat_cancelar($pdo, $id) : notif_programada_cancelar($pdo, $id);
            salir(['ok' => $ok, 'error' => $ok ? null : 'No se pudo cancelar (¿ya salió?)']);
        }

        salir(['ok' => false, 'error' => 'accion desconocida'], 400);
    } catch (Throwable $e) {
        error_log('crm POST: ' . $e->getMessage());
        salir(['ok' => false, 'error' => 'Error', 'detalle' => $e->getMessage()], 500);
    }
}

salir(['ok' => false, 'error' => 'Metodo no permitido'], 405);
