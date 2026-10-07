<?php
/**
 * Pruebas del selector OpenAI del chatbot. No realiza llamadas de red ni usa
 * claves reales.
 *
 *     php t_chat_openai.php
 */
declare(strict_types=1);

require_once __DIR__ . '/api/ia_key.php';

$ok = 0;
$fail = 0;
function comprobar_openai(string $caso, string $modelo, string $clave, bool $esperado): void
{
    global $ok, $fail;
    $real = ia_chat_openai_activo($modelo, $clave);
    if ($real === $esperado) { $ok++; echo "  OK    $caso\n"; }
    else { $fail++; echo "  FALLA $caso\n"; }
}

function comprobar_modelo_resuelto(string $caso, string $configurado, string $clave, string $modeloEsperado, string $modeloOpenAI = 'gpt-5.4-mini'): void
{
    global $ok, $fail;
    $real = ia_chat_model_resolver($configurado, $clave, $modeloOpenAI);
    if ($real === $modeloEsperado) { $ok++; echo "  OK    $caso\n"; }
    else { $fail++; echo "  FALLA $caso (obtenido: $real)\n"; }
}

$KEY = str_repeat('k', 40);
$casos = [
    ['GPT-5.4 mini con clave valida', 'gpt-5.4-mini', $KEY, true],
    ['modelo versionado de OpenAI con clave valida', 'gpt-5.4-mini-2026-03-17', $KEY, true],
    ['prefijo gpt no distingue mayusculas', 'GPT-5.4-mini', $KEY, true],
    ['modelo Claude no se envia a OpenAI', 'claude-haiku-4-5-20251001', $KEY, false],
    ['modelo Qwen no se envia a OpenAI', 'qwen-plus', $KEY, false],
    ['sin modelo configurado', '', $KEY, false],
    ['sin clave OpenAI', 'gpt-5.4-mini', '', false],
    ['clave demasiado corta', 'gpt-5.4-mini', 'sk-test', false],
    ['clave solo con espacios', 'gpt-5.4-mini', str_repeat(' ', 40), false],
];

foreach ($casos as [$nombre, $modelo, $clave, $esperado]) {
    comprobar_openai($nombre, $modelo, $clave, $esperado);
}

echo "\n=== Selección automática al agregar OPENAI_API_KEY ===\n";
comprobar_modelo_resuelto('clave OpenAI activa GPT aunque siga CHAT_MODEL de Claude', 'claude-haiku-4-5-20251001', $KEY, 'gpt-5.4-mini');
comprobar_modelo_resuelto('sin clave OpenAI conserva el modelo Claude', 'claude-haiku-4-5-20251001', '', 'claude-haiku-4-5-20251001');
comprobar_modelo_resuelto('modelo GPT explícito tiene prioridad', 'gpt-5.4-mini-2026-03-17', $KEY, 'gpt-5.4-mini-2026-03-17');
comprobar_modelo_resuelto('OPENAI_CHAT_MODEL permite elegir otro GPT', 'claude-haiku-4-5-20251001', $KEY, 'gpt-5.4-nano', 'gpt-5.4-nano');

printf("\n%d OK, %d fallas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
