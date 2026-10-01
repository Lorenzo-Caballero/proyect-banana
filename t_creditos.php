<?php
/**
 * t_creditos.php — Créditos prepagos: cobrar el 2% y cargar con USDT.
 *
 * EL MODELO con el que se vende el servicio (29/09/2026): el cajero carga
 * créditos en pesos transfiriendo USDT, y se le descuenta un 2% de cada carga
 * que procesa. Sin créditos se le bloquea el CRM.
 *
 * LO QUE ESTOS CHEQUEOS CUIDAN, que es donde esto sale caro:
 *
 *  1. QUE NO SE COBRE DOS VECES LA MISMA CARGA. El cron reenvía una ventana de
 *     72 h en cada pasada, cada 5 minutos. Si la idempotencia se rompe, a un
 *     cliente se le vacía el saldo en una tarde y la culpa parece suya.
 *  2. QUE NO SE PUEDA REGALAR CRÉDITOS CON UN HASH. Cualquiera publica un
 *     token en la cadena y lo llama "USDT"; cualquiera pega el hash de una
 *     transferencia entre dos desconocidos. Las dos cosas son reales y están
 *     en la blockchain.
 *  3. QUE UN HASH NO SIRVA DOS VECES, ni para el mismo cliente ni para dos.
 *  4. QUE ANTE LA DUDA NO SE ACREDITE, pero tampoco se queme el comprobante.
 *
 * Con MySQL (idempotencia real):   T_PORT=3399 php t_creditos.php
 * Sin MySQL corre igual y saltea esa parte.
 */
declare(strict_types=1);

$ok = 0; $fail = 0; $skip = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

require_once __DIR__ . '/api/usdt_lib.php';

const NUESTRA  = 'TXyZgoldpawWalletDestino00000000000';
const AJENA    = 'TOtraBilleteraDeUnDesconocido000000';
const USDT_OK  = USDT_TRC20_CONTRATO;

/** Arma una respuesta de la API con la forma real de tronscan. */
function resp(array $over = []): string {
    $tr = array_merge([
        'contract_address' => USDT_OK,
        'to_address'       => NUESTRA,
        'amount_str'       => '500000000',   // 500 USDT con 6 decimales
        'decimals'         => 6,
    ], $over['tr'] ?? []);
    unset($over['tr']);
    return json_encode(array_merge([
        'hash'          => str_repeat('a', 64),
        'contractRet'   => 'SUCCESS',
        'confirmations' => 50,
        'trc20TransferInfo' => [$tr],
    ], $over));
}

// ===========================================================================
echo "=== 1. No se puede regalar créditos con un hash ===\n";
/* EL ATAQUE BARATO: publicar un token propio llamado "USDT", mandarse un
   millón a uno mismo y canjearlo. El nombre no prueba nada; el contrato sí. */
$r = usdt_interpretar(resp(['tr' => ['contract_address' => 'TFalsoToken111111111111111111111']]), NUESTRA);
chequear('un token falso que se llama USDT se rechaza', $r['estado'] === 'rechazada', $r['motivo']);
chequear('y se dice por qué (el contrato)', str_contains($r['motivo'], 'no es USDT'));

/* Una transferencia entre dos desconocidos también es real y también está en
   la cadena. Lo que la hace nuestra es el destino. */
$r = usdt_interpretar(resp(['tr' => ['to_address' => AJENA]]), NUESTRA);
chequear('una transferencia a otra billetera se rechaza', $r['estado'] === 'rechazada', $r['motivo']);
chequear('y no acredita ni un peso', (float)$r['monto'] === 0.0);

$r = usdt_interpretar(resp(['trc20TransferInfo' => []]), NUESTRA);
chequear('un envío que no es de token (TRX suelto) se rechaza', $r['estado'] === 'rechazada', $r['motivo']);

$r = usdt_interpretar(resp(['contractRet' => 'REVERT']), NUESTRA);
chequear('una transacción que falló en la red se rechaza', $r['estado'] === 'rechazada', $r['motivo']);

$r = usdt_interpretar(resp(['tr' => ['amount_str' => '0']]), NUESTRA);
chequear('una transferencia por cero se rechaza', $r['estado'] === 'rechazada', $r['motivo']);

chequear('un hash con forma inválida ni sale a la red',
         usdt_verificar('no-soy-un-hash', NUESTRA)['estado'] === 'rechazada');
chequear('y el largo exacto importa (63 caracteres no alcanza)',
         !usdt_txid_valido(str_repeat('a', 63)) && usdt_txid_valido(str_repeat('A', 64)));

// ===========================================================================
echo "\n=== 2. La transferencia buena se lee bien ===\n";
$r = usdt_interpretar(resp(), NUESTRA);
chequear('se acepta', $r['estado'] === 'ok', $r['motivo']);
/* 500000000 con 6 decimales son 500 USDT, no 500000000 ni 0,0005. Un error
   acá es un factor de un millón en la plata que se acredita. */
chequear('el monto se convierte con los decimales del token (500 USDT)',
         abs($r['monto'] - 500.0) < 0.000001, 'dio ' . $r['monto']);
chequear('y se guarda a qué billetera llegó', $r['destino'] === NUESTRA);
/* Los centavos de USDT importan: 6 decimales sobre un float se pierden. */
$r2 = usdt_interpretar(resp(['tr' => ['amount_str' => '123456789']]), NUESTRA);
chequear('no se pierden decimales (123,456789 USDT)',
         abs($r2['monto'] - 123.456789) < 0.0000005, 'dio ' . $r2['monto']);

// ===========================================================================
echo "\n=== 3. Ante la duda no se acredita, pero no se quema el comprobante ===\n";
/* PENDIENTE Y NO RECHAZADA: el txid es UNIQUE, así que rechazar una
   transferencia que solo está esperando confirmaciones dejaría al cliente sin
   poder volver a presentarla nunca. */
$r = usdt_interpretar(resp(['confirmations' => 3]), NUESTRA, 19);
chequear('sin confirmaciones suficientes queda PENDIENTE, no rechazada',
         $r['estado'] === 'pendiente',
         'rechazarla quemaría el hash: es UNIQUE y no lo podría volver a presentar');
chequear('y ya sabe cuánto es, para acreditarlo cuando confirme',
         abs($r['monto'] - 500.0) < 0.000001);

$r = usdt_interpretar('no soy json', NUESTRA);
chequear('una respuesta ilegible va a revisión (no se rechaza al cliente)',
         $r['estado'] === 'revision', $r['motivo']);
$r = usdt_interpretar(resp(), '');
chequear('sin billetera configurada va a revisión, no se culpa al cliente',
         $r['estado'] === 'revision', $r['motivo']);
/* UN OBJETO VACÍO NO SE RECHAZA, y este chequeo estaba al revés cuando se
   escribió. Una transacción recién enviada todavía no está indexada y la API
   devuelve {}: rechazarla quemaría el hash --es UNIQUE-- de una transferencia
   que el cliente hizo de verdad, y no la podría volver a presentar nunca. */
$r = usdt_interpretar('{}', NUESTRA);
chequear('un hash que la red todavía no indexó queda PENDIENTE, no rechazado',
         $r['estado'] === 'pendiente',
         'rechazarlo quema el hash de una transferencia que quizá está bien');

/* Y algo tiene que RETOMAR esas pendientes: la plata ya salió de la billetera
   del cliente. Sin esto la fila se queda en 'pendiente' para siempre, el saldo
   nunca sube y del lado nuestro no se rompe nada. */
$cron0 = file_get_contents(__DIR__ . '/panel/consumo_cargas.php');
chequear('el cron retoma las recargas pendientes',
         str_contains($cron0, "WHERE r.estado = 'pendiente'"),
         'sin esto el cliente paga, la red tarda en confirmar, y no se le acredita nunca');
chequear('y al acreditarlas no las puede acreditar dos veces',
         str_contains($cron0, "WHERE id = ? AND estado = 'pendiente'"));
chequear('una pendiente que nunca aparece termina en revisión, no colgada',
         str_contains($cron0, 'PEND_HORAS_REVISION'));

// ===========================================================================
echo "\n=== 4. El código que sostiene el resto ===\n";
$cre = file_get_contents(__DIR__ . '/api/creditos_lib.php');
$sql = file_get_contents(__DIR__ . '/panel/sql/11_creditos.sql');
$end = file_get_contents(__DIR__ . '/api/crm_creditos.php');
$cron= file_get_contents(__DIR__ . '/panel/consumo_cargas.php');

/* UNA SOLA DEFINICIÓN DE CARGA. CLAUDE.md lo cuenta dos veces: Publicidad
   mostró cero conversiones y Finanzas se comió un 10% del negocio, las dos
   por contar solo `recargas`. Cobrar con una definición propia repetiría el
   error con la plata del cliente. */
chequear('el cobro usa publicidad_sql_cargas(), la definición canónica',
         str_contains($cre, 'publicidad_sql_cargas()'),
         'una definición propia subcontaría el botón «Depósitos», como ya pasó dos veces');
chequear('el UNIQUE de consumo es (cliente, via, referencia)',
         str_contains($sql, 'UNIQUE KEY uk_carga (cliente_id, via, referencia)'));
chequear('y el INSERT es IGNORE (reenviar la ventana no cobra de nuevo)',
         str_contains($cre, 'INSERT IGNORE INTO consumos_plataforma'));
chequear('solo se descuenta lo que REALMENTE se insertó',
         str_contains($cre, '$ins->rowCount() > 0'),
         'descontar sin mirar rowCount le vacía el saldo en cada pasada del cron');
/* Sin este corte, activarle el modelo a un cliente con historial le cobraría
   meses de cargas viejas de una. */
chequear('no se cobran las cargas anteriores a creditos_desde',
         str_contains($cre, 'sin creditos_desde: no se cobra nada hasta fijarlo'));
/* El UNIQUE global del hash, no por cliente. */
chequear('el txid es UNIQUE GLOBAL, no por cliente',
         str_contains($sql, 'UNIQUE KEY uk_txid (txid)')
         && !str_contains($sql, 'UNIQUE KEY uk_txid (cliente_id, txid)'),
         'por cliente, el mismo hash cargaría créditos en dos cuentas');
/* Reservar antes de salir a la red: verificar tarda segundos y dos clicks
   entrarían los dos. */
chequear('el hash se reserva ANTES de consultar la cadena',
         strpos($end, 'INSERT INTO recargas_usdt') < strpos($end, 'usdt_verificar($txid'),
         'verificar tarda segundos: dos clicks acreditarían dos veces');
chequear('el cliente NO declara el monto (sale de la cadena)',
         !preg_match('/\$body\[.(monto|importe|usdt).\]/', $end),
         'si lo declarara él, esto sería un formulario para regalarse créditos');
chequear('la cotización aplicada se guarda en la fila',
         str_contains($sql, 'cotizacion') && str_contains($end, "cotizacion = ?"),
         'recalcularla después haría que una recarga vieja no se pueda explicar');
chequear('cargar créditos es solo de admin',
         str_contains($end, 'exigir_admin()'));
chequear('dos clientes con la misma base NO se facturan',
         str_contains($cron, 'NO se factura, comparte la base')
         && str_contains($cre, 'clientes activos comparten'),
         'le cobraría a los dos las cargas de uno');
chequear('el estado se recalcula aunque no se haya cobrado nada',
         str_contains($cron, 'cred_aplicar_estado($ctl, $c, $r[\'saldo\']'),
         'es lo que desbloquea al que acaba de cargar créditos');
chequear('acreditar desbloquea el CRM en el acto',
         str_contains($cre, "\$saldo > 0 && (\$f['suscripcion_estado'] ?? '') === 'sin_saldo'"),
         'si no, paga, lo sigue viendo bloqueado, y lo natural es que pague de nuevo');
chequear('el aviso de saldo bajo no se repite en cada pasada',
         str_contains($cre, 'strtotime($ultimo) < time() - 86400'));
chequear('el modelo nuevo no le cambia la facturación a los que ya andan',
         str_contains($sql, "cobro_modelo ENUM('suscripcion','transaccion') NOT NULL DEFAULT 'suscripcion'"));

// ===========================================================================
echo "\n=== 5. El panel del dueño: lo que se puede romper desde ahí ===\n";
$pan = file_get_contents(__DIR__ . '/panel/panel.php');

foreach (['cred_config_ver', 'cred_config_guardar', 'cred_cliente_guardar',
          'cred_ajustar', 'cred_resumen'] as $a) {
    chequear("existe la acción $a", str_contains($pan, "case '$a':"));
}

/* UNA DIRECCIÓN MAL COPIADA MANDA LA PLATA DEL CLIENTE A LA NADA. Es
   irreversible y el cliente la paga, así que se valida donde todavía se puede
   corregir: al guardarla. */
chequear('la billetera se valida con forma de dirección Tron',
         str_contains($pan, "preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}\$/', \$wallet)"),
         'una dirección mal copiada manda la plata del cliente a la nada, sin vuelta');
$dir = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
chequear('una dirección real de Tron pasa la validación',
         (bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $dir));
chequear('y una recortada no',
         !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', substr($dir, 0, 30)));
/* Base58 no tiene 0, O, I ni l justamente para que no se confundan al
   copiarlas a mano. */
chequear('una con caracteres que Base58 no usa (0/O/I/l) tampoco',
         !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', 'T' . str_repeat('0', 33)));

/* Sin el corte, el primer cron le cobra de una todo el historial que
   encuentre en la ventana. */
chequear('al activar el modelo se fija creditos_desde',
         str_contains($pan, 'creditos_desde = COALESCE(creditos_desde, NOW())'),
         'sin eso el primer cron le cobra las cargas viejas de golpe');
chequear('un ajuste a mano exige motivo',
         str_contains($pan, "poné el motivo del ajuste"),
         'un ajuste sin motivo es un número que en un mes no se puede explicar');
chequear('y queda auditado en ajustes_saldo_plataforma',
         str_contains($pan, "'créditos ARS '"));
chequear('ajustar en positivo desbloquea al que estaba sin saldo',
         str_contains($pan, "creditos_ars + ? > 0 AND suscripcion_estado = 'sin_saldo'"));

// ===========================================================================
echo "\n=== 6. Lo que encontró la auditoría del circuito entero ===\n";
/* Seis fallas reales que el código tenía después de escribirlo, encontradas
   recorriendo el flujo de punta a punta. Cada chequeo de acá es una de ellas:
   si vuelve a aparecer, este test lo dice antes que un cliente. */
$aut = file_get_contents(__DIR__ . '/api/crm_creditos.php');
$dia = file_get_contents(__DIR__ . '/panel/consumo_diario.php');
$lib = file_get_contents(__DIR__ . '/api/creditos_lib.php');
$crn = file_get_contents(__DIR__ . '/panel/consumo_cargas.php');

/* (1) EL PEOR: sin esto el sistema se traba solo. El gate de crm_auth corta
   todo el CRM de un cliente sin créditos; si esta pantalla queda detrás del
   mismo corte, la ÚNICA que lo destraba está bloqueada también. */
chequear('la pantalla de créditos NO pide saldo para entrar',
         str_contains($aut, 'exigir_operador(false)'),
         'sin esto: se queda sin créditos -> CRM bloqueado -> no puede entrar a cargar -> sin salida');
chequear('y el rol admin se sigue exigiendo en POST',
         str_contains($aut, "\$_SERVER['REQUEST_METHOD'] === 'POST' && operador_rol() !== 'admin'"),
         'exigir_admin() no sirve acá: vuelve a pasar por el gate del saldo');
/* Se busca la LLAMADA, no la palabra: el comentario de arriba del archivo
   nombra exigir_admin() justamente para explicar por qué NO se usa, y un
   str_contains pelado lo contaba como si se usara. */
chequear('pero NO se LLAMA a exigir_admin(), que reintroduce el bloqueo',
         !preg_match('/^\s*(\$\w+\s*=\s*)?exigir_admin\(\)\s*;/m', $aut));

/* (2) DOBLE COBRO. El cron viejo no filtraba por modelo: al cliente de
   transacción le cobraba también los ~63 USD/día de la suscripción, y como
   nunca carga `saldo_usd` lo dejaba en 'sin_saldo' a los pocos días tuviera
   los créditos que tuviera. */
chequear('el cron de suscripción NO toca a los del modelo por transacción',
         str_contains($dia, "COALESCE(cobro_modelo, 'suscripcion') = 'suscripcion'"),
         'le cobraba las dos cosas, y lo bloqueaba igual por un saldo_usd que nunca usa');
chequear('y el filtro tolera que la columna no exista todavía',
         str_contains($dia, "COALESCE(cobro_modelo"),
         'sin COALESCE, el cron viejo muere en un servidor sin la migración 11');

/* (3) EL TRIAL. Un cliente nuevo entra con 0 créditos --todavía no
   transfirió-- y la primera pasada del cron lo bloqueaba antes de que pudiera
   mirar el producto. consumo_diario ya respetaba el trial; acá faltaba. */
chequear('un cliente en trial no se bloquea por tener 0 créditos',
         str_contains($lib, "if (\$estado === 'trial'"),
         'un cliente nuevo entra con 0 créditos: lo bloqueaba en la primera pasada');
chequear('y el trial vencido sí vuelve a las reglas normales',
         str_contains($lib, "\$trialHasta >= date('Y-m-d')"));
chequear('el cron le pasa trial_hasta',
         str_contains($crn, "cred_aplicar_estado(\$ctl, \$c, \$r['saldo'], \$c['trial_hasta'] ?? null)")
         && str_contains($crn, 'trial_hasta'));

/* (4) EL CACHÉ DE SESIÓN. crm_auth cachea "sin saldo" 5 minutos. Tirar una
   clave inventada no falla -- no hace nada, que es peor: el cliente paga,
   sigue viendo el CRM bloqueado, y lo natural es que vuelva a pagar. */
chequear('al acreditar se tira la clave de caché REAL',
         str_contains($aut, "unset(\$_SESSION['saldo_plataforma_cache'])"),
         'es la que usa crm_auth; una clave inventada no rompe nada y no hace nada');
chequear('y no quedan claves de caché inventadas',
         !str_contains($aut, 'crm_saldo_cache') && !str_contains($aut, 'crm_tirar_cache_saldo'));

/* (5) exigir_operador() devuelve un STRING. Tratarlo como array dejaba la
   primera letra del nombre como autor del movimiento. */
chequear('el operador se usa como string, no como array',
         !str_contains($aut, "\$operador['usuario']"),
         'exigir_operador() devuelve string: $operador[\'usuario\'] da una letra suelta');

/* (6) Un cliente que no está en el modelo no tiene dónde usar estos créditos:
   dejarlo cargar sería cobrarle por algo que no consume. */
chequear('no se le acepta una carga a quien no está en el modelo',
         str_contains($aut, "Tu plan no se paga con créditos"));

// y el código muerto que quedó de la primera escritura
chequear('sin el SELECT ROW_COUNT() muerto en el cron',
         !str_contains($crn, 'SELECT ROW_COUNT()') && !str_contains($crn, "prepare('SELECT 1')"));

// ===========================================================================
echo "\n=== 7. Idempotencia de verdad, contra MySQL ===\n";
$port = getenv('T_PORT') ?: '';
$pdo = null;
if ($port !== '') {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Throwable $e) { $pdo = null; }
}
if (!$pdo) {
    $skip++;
    echo "  (saltado: sin MySQL. Para correrlo: T_PORT=3399 php t_creditos.php)\n";
} else {
    $pdo->exec('DROP DATABASE IF EXISTS t_cred');
    $pdo->exec('CREATE DATABASE t_cred');
    $pdo->exec('USE t_cred');
    $pdo->exec("CREATE TABLE consumos_plataforma (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NOT NULL,
        via VARCHAR(20) NOT NULL, referencia VARCHAR(100) NOT NULL,
        usuario VARCHAR(60), monto_carga DECIMAL(14,2) NOT NULL,
        comision_pct DECIMAL(5,2) NOT NULL, comision_ars DECIMAL(14,2) NOT NULL,
        cuando DATETIME, cobrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_carga (cliente_id, via, referencia)) ENGINE=InnoDB");

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO consumos_plataforma
           (cliente_id, via, referencia, usuario, monto_carga, comision_pct, comision_ars, cuando)
         VALUES (?,?,?,?,?,?,?,NOW())');

    // Una carga de 10.000 al 2% = 200, que es el ejemplo del dueño.
    $cobrado = 0.0;
    for ($pasada = 1; $pasada <= 5; $pasada++) {
        // el cron reenvía la MISMA ventana cinco veces
        foreach ([['transferencia', 'REC-1', 10000.0], ['juego', 'MOV-9', 5000.0]] as [$via, $ref, $m]) {
            $com = round($m * 2 / 100, 2);
            $ins->execute([1, $via, $ref, 'holatest1', $m, 2.0, $com]);
            if ($ins->rowCount() > 0) { $cobrado += $com; }
        }
    }
    chequear('5 pasadas sobre la misma ventana cobran UNA sola vez',
             abs($cobrado - 300.0) < 0.001,
             "cobró $cobrado en vez de 300 -- el cron corre cada 5 min: esto le vacía el saldo");
    chequear('una carga de 10.000 al 2% cobra 200 (el ejemplo del dueño)',
             abs((float)$pdo->query("SELECT comision_ars FROM consumos_plataforma
                                      WHERE referencia = 'REC-1'")->fetchColumn() - 200.0) < 0.001);
    chequear('quedan 2 filas, una por carga',
             (int)$pdo->query('SELECT COUNT(*) FROM consumos_plataforma')->fetchColumn() === 2);
    /* Los dos caminos de la plata se cobran: si solo se contara `recargas`,
       el del botón «Depósitos» sería gratis. */
    chequear('se cobran las dos vías (transferencia y juego)',
             (int)$pdo->query("SELECT COUNT(DISTINCT via) FROM consumos_plataforma")->fetchColumn() === 2);
    /* La misma referencia en OTRO cliente sí se cobra: la clave lleva el
       cliente adelante. */
    $ins->execute([2, 'transferencia', 'REC-1', 'otro', 1000.0, 2.0, 20.0]);
    chequear('la misma referencia de OTRO cliente sí se cobra', $ins->rowCount() === 1);
    $pdo->exec('DROP DATABASE t_cred');
}

// ===========================================================================
printf("\n%s\n%d OK, %d fallas%s\n", str_repeat('-', 39), $ok, $fail,
       $skip ? " ($skip bloque saltado)" : '');
exit($fail > 0 ? 1 : 0);
