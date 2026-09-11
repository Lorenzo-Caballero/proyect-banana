<?php
/**
 * t_chat_claude.php — El ruteo del chat a Claude vs Qwen.
 *
 * ia_chat() manda el turno a Claude SOLO si ia_chat_claude_activo() dice que si:
 * un modelo claude configurado (CHAT_MODEL) y una key de Anthropic real. Sin
 * eso, el chat sigue en Qwen -- las instalaciones que no lo configuran NO
 * cambian. Este test blinda esa decision.
 *
 * chatbot.php no se puede incluir (corre el request), asi que se replica el
 * predicado EXACTO. Si algun dia divergen, este test deja de proteger: hay que
 * mantenerlos iguales.
 *
 *     php t_chat_claude.php
 */
declare(strict_types=1);

// COPIA EXACTA de ia_chat_claude_activo() (chatbot.php).
function claude_activo(string $model, string $key): bool {
    return $model !== ''
        && stripos($model, 'claude') === 0
        && strlen(trim($key)) > 20;
}

$ok = 0; $fail = 0;
function chequear(string $q, bool $c, string $d = ''): void {
    global $ok, $fail;
    if ($c) { $ok++;  printf("  OK    %s\n", $q); }
    else     { $fail++; printf("  FALLA %s   %s\n", $q, $d); }
}

$KEY_OK   = str_repeat('x', 40);   // key con pinta real (>20)
$KEY_CORTA = 'sk-123';             // muy corta: no es real

echo "=== Cuando SI se usa Claude ===\n";
chequear('modelo claude-sonnet-5 + key real', claude_activo('claude-sonnet-5', $KEY_OK) === true);
chequear('modelo claude-haiku-4-5-20251001 + key real', claude_activo('claude-haiku-4-5-20251001', $KEY_OK) === true);
chequear('mayusculas: Claude-... (stripos, no case-sensitive)', claude_activo('Claude-sonnet-5', $KEY_OK) === true);

echo "\n=== Cuando NO (se queda en Qwen) ===\n";
chequear('CHAT_MODEL vacio -> Qwen', claude_activo('', $KEY_OK) === false);
chequear('modelo qwen-plus -> Qwen', claude_activo('qwen-plus', $KEY_OK) === false);
chequear('un modelo que solo CONTIENE claude en el medio no cuenta', claude_activo('mi-claude', $KEY_OK) === false);
chequear('modelo claude pero SIN key -> Qwen (no manda a un endpoint sin auth)', claude_activo('claude-sonnet-5', '') === false);
chequear('modelo claude con key demasiado corta -> Qwen', claude_activo('claude-sonnet-5', $KEY_CORTA) === false);
chequear('modelo claude con key de espacios -> Qwen', claude_activo('claude-sonnet-5', str_repeat(' ', 40)) === false);

echo "\n---------------------------------------\n";
printf("%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
