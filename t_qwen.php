<?php
/**
 * t_qwen.php — El chat contra Qwen, sin gastar una sola llamada a la API.
 *
 * Existe porque cambiar de proveedor (Cohere -> Qwen) cambia el FORMATO de los
 * mensajes, no solo la URL: la respuesta pasa de `message` a
 * `choices[0].message`, los errores de `message` a `error.message`, y el eco
 * del turno del asistente ya no lleva `tool_plan`. Un error ahi no se ve como
 * un error: el bot conversa igual pero deja de poder cargar fichas.
 *
 * Las respuestas del modelo estan SIMULADAS, asi que esto corre gratis y sin
 * red. Lo que se prueba es nuestro parseo y el bucle de herramientas.
 *
 *     php t_qwen.php
 */
declare(strict_types=1);
// chatbot.php es un endpoint: incluirlo lo ejecutaria. Se extraen las
// funciones puras que interesan.
$src = file_get_contents(__DIR__ . '/api/chatbot.php');
// Extraer solo las dos funciones puras que queremos probar.
foreach (['procesar_chat','ia_texto','tool_salida_limpia'] as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n\}/s', $src, $m)) { eval($m[0]); }
}

$ok=0; $fail=0;
function chequear($q,$c,$d=''){ global $ok,$fail;
  if($c){$ok++; printf("  OK    %s\n",$q);} else {$fail++; printf("  FALLA %s  %s\n",$q,$d);} }

echo "\n=== 1. Respuesta simple (content string, como manda Qwen) ===\n";
$r = procesar_chat([['role'=>'user','content'=>'hola']],
  fn($m) => ['http'=>200,'data'=>['choices'=>[['message'=>['content'=>'Hola! ¿En qué te ayudo?']]]]],
  fn($n,$a) => [], 4);
chequear('devuelve el texto', $r === 'Hola! ¿En qué te ayudo?', $r);

echo "\n=== 2. Content como array de bloques (modelos con vision) ===\n";
$r = procesar_chat([['role'=>'user','content'=>'hola']],
  fn($m) => ['http'=>200,'data'=>['choices'=>[['message'=>['content'=>[['type'=>'text','text'=>'Buenas!']]]]]]],
  fn($n,$a) => [], 4);
chequear('tambien lo entiende', $r === 'Buenas!', $r);

echo "\n=== 3. Ciclo de tool use ===\n";
$llamadas = 0; $toolsEjecutadas = [];
$r = procesar_chat([['role'=>'user','content'=>'cargame 500']],
  function($m) use (&$llamadas) {
    $llamadas++;
    if ($llamadas === 1) {
      return ['http'=>200,'data'=>['choices'=>[['message'=>[
        'content'=>null,
        'tool_calls'=>[['id'=>'call_1','type'=>'function',
                        'function'=>['name'=>'cargar_al_juego','arguments'=>'{"cantidad":500}']]],
      ]]]]];
    }
    // Segunda vuelta: el modelo ya vio el resultado y contesta
    return ['http'=>200,'data'=>['choices'=>[['message'=>['content'=>'Listo, en un rato lo ves.']]]]];
  },
  function($n,$a) use (&$toolsEjecutadas) { $toolsEjecutadas[] = "$n:" . json_encode($a); return ['ok'=>true]; },
  4);
chequear('ejecuto la herramienta', $toolsEjecutadas === ['cargar_al_juego:{"cantidad":500}'],
         json_encode($toolsEjecutadas));
chequear('volvio a preguntarle al modelo', $llamadas === 2, "llamadas=$llamadas");
chequear('devuelve la respuesta final', $r === 'Listo, en un rato lo ves.', $r);

echo "\n=== 4. Error de la API en formato OpenAI ===\n";
try {
  procesar_chat([['role'=>'user','content'=>'x']],
    fn($m) => ['http'=>401,'data'=>['error'=>['message'=>'Invalid API-key provided.']]],
    fn($n,$a) => [], 4);
  chequear('lanza excepcion', false);
} catch (RuntimeException $e) {
  chequear('lanza con el detalle util', strpos($e->getMessage(),'Invalid API-key') !== false, $e->getMessage());
}

echo "\n=== 5. Se rinde tras demasiadas vueltas, sin colgarse ===\n";
$r = procesar_chat([['role'=>'user','content'=>'x']],
  fn($m) => ['http'=>200,'data'=>['choices'=>[['message'=>['content'=>null,
     'tool_calls'=>[['id'=>'c','type'=>'function','function'=>['name'=>'x','arguments'=>'{}']]]]]]]],
  fn($n,$a) => ['ok'=>true], 3);
chequear('sale con un mensaje al jugador', strpos($r,'problema') !== false, $r);

printf("\n---------------------------------------\n%d OK, %d fallas\n", $ok, $fail);
exit($fail>0?1:0);
