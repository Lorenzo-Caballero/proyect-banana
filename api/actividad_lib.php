<?php
/**
 * actividad_lib.php — "¿Hace cuánto que este jugador no aparece?"
 *
 * POR QUE EXISTE
 * Hasta ahora habia UNA sola fecha confiable de que un jugador hizo algo:
 * recargas.acreditada_en. De ahi colgaba TODO -- el filtro de inactivos, la
 * retencion, los "activos" de Finanzas y los envios masivos. O sea que
 * "activo" en realidad significaba "puso plata", y un jugador que entra
 * todos los dias a jugar con el saldo que ya tenia figuraba como inactivo
 * desde siempre.
 *
 * La columna pensada justamente para esto, usuarios.ultima_actividad, existia
 * desde la migracion 07 y el CRM la mostraba en la ficha de cada jugador...
 * pero NADIE la escribia nunca. Estaba en NULL para todos desde el dia uno.
 *
 * QUE CUENTA COMO ACTIVIDAD
 * Cualquier señal de que la persona esta del otro lado. En orden de que tan
 * directa es:
 *   - reporta saldo desde la pagina del juego  (saldo_reportar.php) <- la mas
 *     directa: la manda widget.js desde ADENTRO del juego, es "esta jugando"
 *   - pide cargar fichas al juego              (fichas_lib.php)
 *   - se le acredita una recarga               (recargas_lib.php)
 *   - escribe por el chat                      (chatbot.php)
 *   - inicia sesion en el sitio                (auth.php)
 *
 * Ninguna de estas se perdia por falta de datos: las cinco ya pasaban por el
 * server. Lo que faltaba era anotarlas en un solo lugar.
 *
 * NO cuentan como actividad las cosas que hace el SISTEMA sobre el jugador:
 * el sync de saldos (corre cada 5 min para todos), una notificacion enviada,
 * o que el agente le cargue fichas a mano. Eso mide nuestra actividad, no la
 * de el -- y contarlo dejaria a todos "activos" para siempre.
 */

declare(strict_types=1);

if (!function_exists('actividad_marcar')) {
    /**
     * Deja constancia de que el jugador aparecio recien.
     *
     * Best-effort a proposito: es un dato de reporteria, y NUNCA puede hacer
     * fallar la operacion real que lo disparo. Si esto explota, el jugador
     * igual tiene que poder cargar sus fichas.
     *
     * Un solo UPDATE, sin leer antes: no hace falta saber el valor anterior y
     * pisarlo siempre es exactamente lo que se quiere.
     */
    function actividad_marcar(PDO $pdo, ?string $usuario): void
    {
        $usuario = trim((string)$usuario);
        if ($usuario === '') {
            return;
        }
        try {
            $pdo->prepare("UPDATE usuarios SET ultima_actividad = NOW() WHERE username = ?")
                ->execute([$usuario]);
        } catch (Throwable $e) {
            error_log('actividad_marcar: ' . $e->getMessage());
        }
    }
}
