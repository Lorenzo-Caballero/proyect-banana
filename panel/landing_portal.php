<?php
/**
 * Portal de autoservicio para clientes del producto Landing (sin CRM).
 * Los clientes no reciben acceso a panel.php ni al plano de control.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/panel_config.php';
if (!is_file($cfgFile)) { portal_out(['ok'=>false,'error'=>'Servicio no configurado'], 500); }
$cfg = require $cfgFile;
try {
    $ctl = new PDO("mysql:host={$cfg['DB_HOST']};dbname={$cfg['DB_NAME']};charset=utf8mb4",
        $cfg['DB_USER'], $cfg['DB_PASS'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    error_log('landing_portal control DB: '.$e->getMessage());
    portal_out(['ok'=>false,'error'=>'No se pudo conectar. Probá más tarde.'], 500);
}

function portal_out(array $data, int $code=200): void {
    http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
function portal_body(): array {
    $x=json_decode((string)file_get_contents('php://input'),true);
    return is_array($x)?$x:[];
}
function portal_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function portal_b64d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function portal_token(int $id, string $hash, string $secret): string {
    $p=portal_b64(json_encode(['id'=>$id,'v'=>hash('sha256',$hash),'exp'=>time()+43200]));
    return $p.'.'.portal_b64(hash_hmac('sha256',$p,$secret,true));
}
function portal_claims(string $token, string $secret): ?array {
    $parts=explode('.',$token);
    if(count($parts)!==2) return null;
    [$p,$sig]=$parts;
    if(!hash_equals(portal_b64(hash_hmac('sha256',$p,$secret,true)),$sig)) return null;
    $d=json_decode(portal_b64d($p),true);
    if(!is_array($d)||(int)($d['exp']??0)<time()||(int)($d['id']??0)<=0) return null;
    return $d;
}
function portal_bearer(): string {
    $h=$_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    return preg_match('/Bearer\s+(.+)/i',$h,$m)?trim($m[1]):'';
}
function portal_cliente(PDO $ctl, array $claims): array {
    $st=$ctl->prepare("SELECT id,nombre,slug,ruta_slug,dominio,path_tenant,db_nombre,estado,producto,altas_propias,
                              agente_usuario,agente_password,landing_portal_password_hash
                         FROM clientes WHERE id=? LIMIT 1");
    $st->execute([(int)$claims['id']]);
    $c=$st->fetch();
    if(!$c || $c['estado']!=='activo' || $c['producto']!=='landing'
       || (int)$c['path_tenant']!==1 || (int)$c['altas_propias']!==1
       || !is_string($c['landing_portal_password_hash'])
       || !hash_equals((string)$claims['v'],hash('sha256',(string)$c['landing_portal_password_hash']))) {
        portal_out(['ok'=>false,'error'=>'La sesión venció o el acceso ya no está activo. Volvé a entrar.'],401);
    }
    if(!preg_match('/^[a-z0-9_]+$/i',(string)$c['db_nombre'])) portal_out(['ok'=>false,'error'=>'La cuenta todavía se está preparando.'],503);
    return $c;
}
function portal_db(array $cfg, array $c): PDO {
    return new PDO("mysql:host={$cfg['DB_HOST']};dbname={$c['db_nombre']};charset=utf8mb4",
        $cfg['DB_USER'],$cfg['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function portal_base(array $c): string {
    $ruta=rawurlencode((string)($c['ruta_slug']?:$c['slug']));
    return 'https://'.rtrim((string)$c['dominio'],'/').'/'.$ruta.'/';
}
function portal_landing(PDO $db): ?array {
    $st=$db->query("SELECT id,slug,nombre,bono_pct,activa,config FROM landings WHERE plantilla='wa' ORDER BY id DESC LIMIT 1");
    $r=$st->fetch();
    if(!$r) return null;
    $config=json_decode((string)($r['config']??''),true);
    if(!is_array($config)) $config=[];
    return ['id'=>(int)$r['id'],'slug'=>(string)$r['slug'],'nombre'=>(string)$r['nombre'],
        'activa'=>(int)$r['activa'],'whatsapp'=>(string)($config['whatsapp']['numero']??''),
        'wa_texto'=>(string)($config['whatsapp']['texto']??'')];
}

$in=portal_body();
$accion=(string)($in['accion']??$_GET['accion']??'');
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && $accion==='login') {
    $u=trim((string)($in['usuario']??'')); $p=(string)($in['password']??'');
    if($u===''||$p==='') portal_out(['ok'=>false,'error'=>'Ingresá tu usuario y contraseña.'],422);
    try {
        $st=$ctl->prepare("SELECT id,landing_portal_password_hash FROM clientes WHERE landing_portal_usuario=? AND producto='landing' AND estado='activo' LIMIT 1");
        $st->execute([$u]); $row=$st->fetch();
    } catch(Throwable $e) {
        portal_out(['ok'=>false,'error'=>'Falta actualizar el sistema. Avisale al administrador.'],503);
    }
    if(!$row || !password_verify($p,(string)$row['landing_portal_password_hash'])) portal_out(['ok'=>false,'error'=>'Usuario o contraseña incorrectos.'],401);
    portal_out(['ok'=>true,'token'=>portal_token((int)$row['id'],(string)$row['landing_portal_password_hash'],(string)$cfg['PANEL_SECRET'])]);
}

$claims=portal_claims(portal_bearer(),(string)$cfg['PANEL_SECRET']);
if(!$claims) portal_out(['ok'=>false,'error'=>'Iniciá sesión para continuar.'],401);
$c=portal_cliente($ctl,$claims);
try { $db=portal_db($cfg,$c); }
catch(Throwable $e) { error_log('landing_portal tenant DB: '.$e->getMessage()); portal_out(['ok'=>false,'error'=>'Tu espacio se está preparando. Probá de nuevo en un minuto.'],503); }

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && $accion==='cambiar_clave') {
    $actual=(string)($in['clave_actual']??''); $nueva=(string)($in['clave_nueva']??'');
    if(!password_verify($actual,(string)$c['landing_portal_password_hash'])) portal_out(['ok'=>false,'error'=>'La contraseña actual no es correcta.'],403);
    if(strlen($nueva)<10 || strlen($nueva)>200) portal_out(['ok'=>false,'error'=>'La nueva contraseña debe tener entre 10 y 200 caracteres.'],422);
    $hash=password_hash($nueva,PASSWORD_DEFAULT);
    $ctl->prepare('UPDATE clientes SET landing_portal_password_hash=? WHERE id=?')->execute([$hash,(int)$c['id']]);
    portal_out(['ok'=>true,'token'=>portal_token((int)$c['id'],$hash,(string)$cfg['PANEL_SECRET'])]);
}

if(($_SERVER['REQUEST_METHOD']??'')==='GET' && $accion==='estado') {
    try { $l=portal_landing($db); }
    catch(Throwable $e) { portal_out(['ok'=>false,'error'=>'La base del cliente todavía está actualizándose.'],503); }
    $cred=trim((string)$c['agente_usuario'])!=='' && trim((string)$c['agente_password'])!=='';
    $bot=''; $botEn='';
    try {
        $q=$db->prepare("SELECT clave,valor FROM config_crm WHERE clave IN ('bot_altas_prov','bot_altas_prov_en')");
        $q->execute();
        foreach($q->fetchAll() as $row) {
            if($row['clave']==='bot_altas_prov') $bot=(string)$row['valor'];
            if($row['clave']==='bot_altas_prov_en') $botEn=(string)$row['valor'];
        }
    } catch(Throwable $e) {}
    $tsBot=$botEn!==''?strtotime($botEn):false;
    $botListo=preg_match('/^bot de altas (?:levantado|ya existía)$/u',trim($bot))===1
        && $tsBot!==false && $tsBot<=time() && (time()-$tsBot)<=180;
    portal_out(['ok'=>true,'cliente'=>(string)$c['nombre'],'usuario_ganamos'=>(string)$c['agente_usuario'],
        'credenciales_cargadas'=>$cred,'bot_estado'=>$bot,
        'bot_listo'=>$botListo,
        'bot_estado_en'=>$botEn,'landing'=>$l,
        'url'=>$l?portal_base($c).'lp.html?l='.rawurlencode($l['slug']):'']);
}

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && $accion==='guardar') {
    require_once __DIR__.'/../api/landings_lib.php';
    if(!isset(landings_plantillas()['wa'])) portal_out(['ok'=>false,'error'=>'La plantilla de landing no está instalada.'],503);
    $usr=trim((string)($in['agente_usuario']??''));
    $pass=(string)($in['agente_password']??'');
    $actualUsr=trim((string)$c['agente_usuario']); $actualPass=(string)$c['agente_password'];
    if($usr==='') $usr=$actualUsr;
    if($usr==='' || ($pass==='' && $actualPass==='')) portal_out(['ok'=>false,'error'=>'Completá el usuario y la contraseña de tu panel Ganamos.'],422);
    if(mb_strlen($usr)>120 || mb_strlen($pass)>190) portal_out(['ok'=>false,'error'=>'Las credenciales exceden el largo permitido.'],422);
    if($pass==='') $pass=$actualPass;
    $wa=trim((string)($in['whatsapp']??''));
    if(landings_wa_numero($wa)==='') portal_out(['ok'=>false,'error'=>'Ingresá un WhatsApp con código de país, por ejemplo +54 9 11 2345-6789.'],422);
    $nombre=trim((string)($in['nombre']??''));
    if($nombre==='') $nombre=(string)$c['nombre'];
    $txt=mb_substr(trim((string)($in['wa_texto']??'')),0,120);
    $lid=(int)($in['landing_id']??0);
    $config=['whatsapp'=>['numero'=>$wa]];
    if($txt!=='') $config['whatsapp']['texto']=$txt;
    if($lid>0) {
        $q=$db->prepare("SELECT id FROM landings WHERE id=? AND plantilla='wa' LIMIT 1");
        $q->execute([$lid]); if(!$q->fetchColumn()) portal_out(['ok'=>false,'error'=>'La landing ya no existe. Actualizá la página y volvé a intentar.'],409);
    }
    $r=landings_guardar($db,$lid?:null,$nombre,'wa',0,$config);
    if(!$r) portal_out(['ok'=>false,'error'=>'No se pudo guardar la landing. Revisá las migraciones del sistema.'],500);
    $db->prepare('UPDATE landings SET activa=1 WHERE id=?')->execute([(int)$r['id']]);
    $credencialesCambiaron=$pass!==(string)$c['agente_password'] || $usr!==$actualUsr;
    try {
        if($credencialesCambiaron) {
            // Un estado "listo" de las credenciales anteriores no debe
            // habilitar la URL mientras provisionar.php aún recrea el bot.
            $db->prepare("INSERT INTO config_crm (clave,valor) VALUES ('bot_altas_prov','esperando aprovisionamiento de credenciales')
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute();
            $db->prepare("INSERT INTO config_crm (clave,valor) VALUES ('bot_altas_prov_en',?)
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([date('Y-m-d H:i:s')]);
            $up=$ctl->prepare('UPDATE clientes SET agente_usuario=?, agente_password=?, altas_propias=1 WHERE id=?');
            $up->execute([$usr,$pass,(int)$c['id']]);
        }
    } catch(Throwable $e) {
        error_log('landing_portal save credentials: '.$e->getMessage());
        portal_out(['ok'=>false,'landing_guardada'=>true,'error'=>'La landing se guardó, pero no pudimos guardar las credenciales. Volvé a guardar para completar la conexión.'],500);
    }
    portal_out(['ok'=>true,'url'=>portal_base($c).'lp.html?l='.rawurlencode($r['slug']),
        'slug'=>$r['slug'],'aviso'=>'Guardado. El sistema conectará tu cuenta en breve; compartí el enlace cuando el estado indique que el bot de altas está listo.']);
}

portal_out(['ok'=>false,'error'=>'Acción desconocida.'],400);
