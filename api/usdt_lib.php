<?php
/**
 * usdt_lib.php — Verificar contra la blockchain que una transferencia USDT
 * llegó de verdad a nuestra billetera.
 *
 * EL CLIENTE NO NOS MANDA PLATA POR ACÁ: transfiere por su cuenta a nuestra
 * dirección y después pega el HASH de la transacción en su CRM. Esto lee la
 * cadena —que es pública— y contesta si ese hash es una transferencia real de
 * USDT a nuestra billetera, por cuánto, y con cuántas confirmaciones.
 *
 * ============================================================================
 * POR QUÉ ASÍ Y NO CON UNA BILLETERA POR CLIENTE.
 *
 * Lo "natural" sería darle a cada cajero su propia dirección y detectar los
 * depósitos solos. Eso obliga a generar y CUSTODIAR una llave privada por
 * cliente: pasamos a ser responsables de la plata de otro, con backups, y un
 * servidor comprometido deja de ser un incidente de datos para ser un robo.
 *
 * Con el hash no custodiamos nada. Solo leemos. La llave de la billetera puede
 * vivir en un hardware wallet que jamás toca el servidor, y lo peor que puede
 * pasar acá es que alguien lea transacciones que ya son públicas.
 *
 * ============================================================================
 * LO QUE SE VERIFICA, Y POR QUÉ CADA COSA.
 *
 *  1. QUE EL CONTRATO SEA USDT. Sin esto, cualquiera crea un token propio
 *     llamado "USDT", se manda un millón a sí mismo y lo canjea por créditos.
 *     Es el ataque obvio y barato.
 *  2. QUE EL DESTINO SEA NUESTRA BILLETERA. Un hash de una transferencia entre
 *     dos desconocidos también es real y también está en la cadena.
 *  3. QUE HAYA CONFIRMACIONES. Una transacción puede revertirse mientras no
 *     esté confirmada.
 *  4. QUE EL HASH NO SE HAYA USADO ANTES. Eso no se resuelve acá sino con el
 *     UNIQUE global de `recargas_usdt.txid`: es la misma guarda que
 *     `pagos.id_unico` en las recargas de jugadores.
 *
 * ANTE LA DUDA NO SE ACREDITA. Cada chequeo que no se pueda hacer con
 * evidencia devuelve 'revision', no 'ok': un hash raro lo mira una persona,
 * que cuesta dos minutos. Acreditar de más cuesta plata y no se entera nadie.
 */

declare(strict_types=1);

/** El contrato de USDT en Tron (TRC20). Es una constante de la red, no config:
 *  si esto fuera configurable, cargar mal el valor abriría el ataque del token
 *  falso y no se vería hasta que alguien lo usara. */
const USDT_TRC20_CONTRATO = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

/** USDT en Tron tiene 6 decimales. */
const USDT_TRC20_DECIMALES = 6;

/** Confirmaciones mínimas si el panel no dice otra cosa. ~1 minuto en Tron. */
const USDT_CONFIRMACIONES_MIN = 19;

/**
 * ¿Tiene forma de hash de Tron? Se chequea antes de salir a la red: evita
 * pegarle a la API por cada cosa que alguien pegue en el campo.
 */
function usdt_txid_valido(string $txid): bool
{
    return (bool)preg_match('/^[0-9a-f]{64}$/i', trim($txid));
}

/**
 * Consulta la cadena. Devuelve:
 *   ['estado' => 'ok'|'rechazada'|'revision', 'motivo' => '...',
 *    'monto' => float, 'destino' => '...', 'confirmaciones' => int, 'raw' => '...']
 *
 * `$destinoEsperado` es NUESTRA billetera; `$minConf` las confirmaciones que
 * se exigen.
 */
function usdt_verificar(string $txid, string $destinoEsperado, int $minConf = USDT_CONFIRMACIONES_MIN): array
{
    $txid = strtolower(trim($txid));
    $no = function (string $estado, string $motivo, array $extra = []) {
        return array_merge(['estado' => $estado, 'motivo' => $motivo,
                            'monto' => 0.0, 'destino' => '', 'confirmaciones' => 0, 'raw' => ''], $extra);
    };

    if (!usdt_txid_valido($txid)) {
        return $no('rechazada', 'ese no es un hash de transacción válido (tienen 64 caracteres)');
    }
    if (trim($destinoEsperado) === '') {
        // Sin billetera configurada no se puede verificar NADA. Rechazar acá
        // sería culpar al cliente de algo nuestro.
        return $no('revision', 'todavía no hay una billetera configurada de este lado');
    }

    $r = usdt_http('https://apilist.tronscanapi.com/api/transaction-info?hash=' . urlencode($txid));
    if ($r === null) {
        // No poder preguntar NO es evidencia de nada: que lo mire una persona.
        return $no('revision', 'no se pudo consultar la red en este momento');
    }
    return usdt_interpretar($r, $destinoEsperado, $minConf);
}

/**
 * Lo mismo que usdt_verificar() pero sobre una respuesta YA obtenida.
 *
 * Está separado a propósito: acá viven los chequeos que paran un fraude, y así
 * se puede probar cada ataque --el token falso, el destino ajeno, el monto en
 * cero-- sin depender de que haya red ni de que exista una transacción real en
 * la cadena con esa forma. Ver t_creditos.php.
 */
function usdt_interpretar(string $r, string $destinoEsperado, int $minConf = USDT_CONFIRMACIONES_MIN): array
{
    $no = function (string $estado, string $motivo, array $extra = []) {
        return array_merge(['estado' => $estado, 'motivo' => $motivo,
                            'monto' => 0.0, 'destino' => '', 'confirmaciones' => 0, 'raw' => ''], $extra);
    };
    if (trim($destinoEsperado) === '') {
        return $no('revision', 'todavía no hay una billetera configurada de este lado');
    }
    $j = json_decode($r, true);
    /* «No se pudo leer» y «leí bien, y está vacío» NO son lo mismo, y
       mezclarlos manda a revisión transferencias que solo hay que esperar.
       Una respuesta que no es JSON es un problema nuestro o de la API; un
       JSON válido y vacío es la forma en que esta API dice «esa transacción
       todavía no la tengo». */
    if (!is_array($j)) {
        return $no('revision', 'la red contestó algo que no se pudo leer', ['raw' => mb_substr($r, 0, 2000)]);
    }
    /* UN OBJETO VACÍO ES «TODAVÍA NO LA VEO», NO «NO EXISTE», y la diferencia
       vale una transferencia. Una transacción recién enviada tarda en
       indexarse: el cliente transfiere, pega el hash en el acto y la API
       contesta {}. Rechazar ahí quemaría el hash --es UNIQUE-- y el cliente se
       quedaría sin poder presentar nunca una transferencia que hizo de verdad.

       Queda PENDIENTE y el cron la retoma. Si pasa el plazo sin aparecer, ahí
       sí se la manda a revisión, que es cuando ya significa algo. */
    if (!$j || (empty($j['hash']) && empty($j['contractRet']))) {
        return $no('pendiente', 'todavía no aparece en la red (puede tardar unos minutos)',
                   ['raw' => mb_substr($r, 0, 2000)]);
    }

    $raw = mb_substr($r, 0, 4000);

    // ---- que la transacción haya salido bien -----------------------------
    $ret = strtoupper((string)($j['contractRet'] ?? ($j['contract_ret'] ?? '')));
    if ($ret !== '' && $ret !== 'SUCCESS') {
        return $no('rechazada', 'la transacción falló en la red (' . $ret . ')', ['raw' => $raw]);
    }

    // ---- confirmaciones --------------------------------------------------
    $conf = (int)($j['confirmations'] ?? 0);
    if ($conf <= 0 && !empty($j['confirmed'])) { $conf = $minConf; }

    // ---- la transferencia de token --------------------------------------
    /* tronscan devuelve la info del token en `trc20TransferInfo` (lista) o en
       `tokenTransferInfo` (objeto). Se aceptan las dos formas: cambió entre
       versiones de la API y no vale la pena atarse a una. */
    $tr = null;
    if (!empty($j['trc20TransferInfo']) && is_array($j['trc20TransferInfo'])) {
        $tr = $j['trc20TransferInfo'][0] ?? null;
    }
    if (!$tr && !empty($j['tokenTransferInfo']) && is_array($j['tokenTransferInfo'])) {
        $tr = $j['tokenTransferInfo'];
    }
    if (!is_array($tr)) {
        return $no('rechazada', 'ese hash no es una transferencia de USDT (¿mandaste TRX u otro token?)',
                   ['raw' => $raw, 'confirmaciones' => $conf]);
    }

    // ---- 1. QUE SEA USDT DE VERDAD ---------------------------------------
    $contrato = (string)($tr['contract_address'] ?? ($tr['contractAddress'] ?? ''));
    if (strcasecmp($contrato, USDT_TRC20_CONTRATO) !== 0) {
        /* El ataque del token falso: cualquiera publica un token con el nombre
           "USDT" y se manda lo que quiera. El nombre no prueba nada; el
           contrato sí. */
        return $no('rechazada', 'ese token no es USDT (el contrato no coincide)',
                   ['raw' => $raw, 'confirmaciones' => $conf]);
    }

    // ---- 2. QUE HAYA LLEGADO A NUESTRA BILLETERA -------------------------
    $destino = (string)($tr['to_address'] ?? ($tr['toAddress'] ?? ''));
    if (strcasecmp(trim($destino), trim($destinoEsperado)) !== 0) {
        return $no('rechazada', 'esa transferencia no fue a nuestra billetera',
                   ['raw' => $raw, 'destino' => $destino, 'confirmaciones' => $conf]);
    }

    // ---- el monto ---------------------------------------------------------
    $bruto = (string)($tr['amount_str'] ?? ($tr['amount'] ?? '0'));
    $dec   = (int)($tr['decimals'] ?? USDT_TRC20_DECIMALES);
    if ($bruto === '' || !preg_match('/^\d+$/', $bruto)) {
        return $no('revision', 'no se pudo leer el monto de la transferencia',
                   ['raw' => $raw, 'destino' => $destino, 'confirmaciones' => $conf]);
    }
    /* En unidades enteras y con bcdiv si está: un USDT son 1.000.000 unidades
       y los float se comen centavos en ese orden de magnitud. */
    $monto = function_exists('bcdiv')
        ? (float)bcdiv($bruto, (string)(10 ** $dec), 6)
        : ((float)$bruto / (10 ** $dec));

    if ($monto <= 0) {
        return $no('rechazada', 'la transferencia es por cero', ['raw' => $raw, 'confirmaciones' => $conf]);
    }

    // ---- 3. CONFIRMACIONES ------------------------------------------------
    if ($conf < $minConf) {
        /* NO es un rechazo: la transferencia probablemente esté bien y solo
           falte esperar. Rechazarla quemaría el hash --es UNIQUE-- y el
           cliente no podría volver a presentarlo cuando confirme. */
        return ['estado' => 'pendiente',
                'motivo' => 'la red todavía la está confirmando (' . $conf . ' de ' . $minConf . ')',
                'monto' => $monto, 'destino' => $destino, 'confirmaciones' => $conf, 'raw' => $raw];
    }

    return ['estado' => 'ok', 'motivo' => '', 'monto' => $monto,
            'destino' => $destino, 'confirmaciones' => $conf, 'raw' => $raw];
}

/** GET con timeout corto. Devuelve el cuerpo o null. */
function usdt_http(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'goldpaw/1.0',
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($out !== false && $code >= 200 && $code < 300) ? (string)$out : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $out = @file_get_contents($url, false, $ctx);
    return $out === false ? null : (string)$out;
}
