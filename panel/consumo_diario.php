<?php
/**
 * consumo_diario.php — Descuenta el prorrateo diario de la suscripción de
 * cada cliente a la plataforma (no confundir con usuarios.balance/coins/bonus,
 * que es plata de los JUGADORES adentro de la base de cada cliente).
 *
 * Corre UNA VEZ contra goldpaw_control (es un solo cron para todos los
 * clientes de una vez -- no tiene sentido resolver por dominio como db.php).
 *
 * Cron sugerido (una vez al día):
 *   10 0 * * *  cd /var/www/panel && php consumo_diario.php >> /var/log/gp-consumo.log 2>&1
 *
 * Best-effort: un cliente que falla no frena a los demás, mismo criterio que
 * aplicar_migraciones() en provisionar.php.
 */

$cfg = require __DIR__ . '/panel_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[" . date('c') . "] no pude conectar a la maestra\n");
    exit(1);
}

// Candidatos: activos, que no estén en trial vigente, y que no se les haya
// descontado ya hoy (ultimo_consumo evita doble descuento si el cron corre
// dos veces en el mismo día).
$clientes = $pdo->query(
    "SELECT id, saldo_usd, costo_diario_usd, suscripcion_estado, trial_hasta, ultimo_consumo
       FROM clientes
      WHERE estado = 'activo'
        AND (ultimo_consumo IS NULL OR ultimo_consumo < CURDATE())"
)->fetchAll();

$procesados = 0;
$bloqueados = 0;

foreach ($clientes as $c) {
    $id = (int) $c['id'];
    try {
        // Todavía en cortesía: no se descuenta, pero sí se marca ultimo_consumo
        // para no re-evaluarlo en cada corrida del día.
        if ($c['suscripcion_estado'] === 'trial' && $c['trial_hasta'] !== null && $c['trial_hasta'] >= date('Y-m-d')) {
            $pdo->prepare('UPDATE clientes SET ultimo_consumo = CURDATE() WHERE id = ?')->execute([$id]);
            continue;
        }
        // El trial venció: pasa a activa recién ahora, para que el descuento
        // de abajo ya la trate como suscripción paga.
        if ($c['suscripcion_estado'] === 'trial') {
            $pdo->prepare("UPDATE clientes SET suscripcion_estado = 'activa' WHERE id = ?")->execute([$id]);
        }

        // Guard optimista: solo descuenta si ultimo_consumo sigue siendo el
        // mismo valor leído arriba (evita doble descuento ante una corrida
        // solapada del cron).
        $upd = $pdo->prepare(
            'UPDATE clientes SET saldo_usd = saldo_usd - costo_diario_usd, ultimo_consumo = CURDATE()
              WHERE id = ? AND (ultimo_consumo IS NULL OR ultimo_consumo < CURDATE())
                AND (ultimo_consumo <=> ?)'
        );
        $upd->execute([$id, $c['ultimo_consumo']]);
        if ($upd->rowCount() !== 1) { continue; }

        $st = $pdo->prepare('SELECT saldo_usd FROM clientes WHERE id = ?');
        $st->execute([$id]);
        $saldoNuevo = (float) $st->fetchColumn();

        if ($saldoNuevo <= 0) {
            $pdo->prepare("UPDATE clientes SET suscripcion_estado = 'sin_saldo' WHERE id = ?")->execute([$id]);
            $bloqueados++;
        }
        $procesados++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[" . date('c') . "] cliente $id: " . $e->getMessage() . "\n");
    }
}

echo date('c') . " consumo diario: $procesados procesados, $bloqueados sin saldo\n";
