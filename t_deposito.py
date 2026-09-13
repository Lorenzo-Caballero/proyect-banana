# -*- coding: utf-8 -*-
"""t_deposito.py -- que un HTTP 200 no se confunda con un deposito hecho.

EL BUG QUE BLINDA (13/9/2026): el worker marcaba 'hecha' cualquier respuesta
2xx sin mirar el cuerpo. Dos jugadores perdieron fichas asi:
  - holamiliii550   -> cuerpo "<!DOCTYPE html>..." (la pagina del WAF)
  - holalourdes220  -> {"status":501,...} x2, 30.000 + 37.500 fichas
En los dos casos se descontaron las fichas, no se deposito nada, y la accion
quedo 'hecha': nadie se entero hasta que los jugadores reclamaron.

    python t_deposito.py
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'colector'))

# ejecutar_cargas importa playwright/dotenv al cargarse; se lee la funcion sola.
import ast, io, json
fuente = io.open(os.path.join('colector', 'ejecutar_cargas.py'), encoding='utf-8').read()
arbol = ast.parse(fuente)
quiero = ('leer_respuesta_deposito', 'es_challenge_waf')
fns = [n for n in arbol.body if isinstance(n, ast.FunctionDef) and n.name in quiero]
assert len(fns) == 2, [f.name for f in fns]
ns = {'json': json}
exec(compile(ast.Module(body=fns, type_ignores=[]), '<t>', 'exec'), ns)
leer = ns['leer_respuesta_deposito']
es_waf = ns['es_challenge_waf']

ok = fallas = 0
def chequear(que, cond, detalle=''):
    global ok, fallas
    if cond:
        ok += 1;     print(u"  OK    %s" % que)
    else:
        fallas += 1; print(u"  FALLA %s   %s" % (que, detalle))

print("\n=== Lo que SI es un deposito hecho ===")
v, _ = leer('{"status":0,"result":{"transfer_details":{"id":1}}}')
chequear("status 0 -> ok", v == "ok", v)

print("\n=== Los dos casos reales que costaron fichas ===")
v, d = leer('<!DOCTYPE html>\n<html>\n<head>\n  <meta charset="utf-8">')
chequear("HTML del WAF -> NO es 'ok'", v != "ok", v)
chequear("y queda para revisar, no se devuelven fichas solas", v == "dudoso", v)
v, d = leer('{"status":501,"result":{},"error_message":"no se pudo"}')
chequear("status 501 -> 'error' (rechazo explicito)", v == "error", v)
chequear("y el detalle dice el motivo", "501" in d, d)

print("\n=== Respuestas raras: nunca 'ok' ===")
for cuerpo, etiq in [
    ("", "vacia"),
    ("   ", "solo espacios"),
    ("no soy json", "texto suelto"),
    ("[1,2,3]", "JSON que no es objeto"),
    ('{"result":{}}', "objeto sin 'status'"),
    ('{"status":"ok"}', "status no numerico"),
    ('<html><body>Access denied</body></html>', "pagina de error del proxy"),
]:
    v, _ = leer(cuerpo)
    chequear("%-28s -> no es 'ok'" % etiq, v != "ok", v)

print("\n=== Un cuerpo largo se parsea igual (antes se recortaba a 300) ===")
grande = json.dumps({"status": 0, "result": {"relleno": "x" * 900}})
v, _ = leer(grande)
chequear("JSON de 900+ chars con status 0 -> ok", v == "ok", v)
grande_mal = json.dumps({"status": 501, "error_message": "y" * 900})
v, _ = leer(grande_mal)
chequear("y uno largo con error -> 'error'", v == "error", v)

print("\n=== El challenge del WAF se reconoce como tal (accion 90, 13/9) ===")
# Copiado literal de acciones_saldo.mensaje: es la firma de ServicePipe.
WAF_REAL = (
    '<!DOCTYPE html>\n<html>\n<head>\n'
    '  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">\n'
    '  <noscript><meta http-equiv="refresh" content="0; url=/exhkqyad"></noscript>\n'
    '  <meta name="viewport" content="width=device-width, initial-scale=1.0">'
)
chequear("el HTML real de holamiliii550 se detecta como WAF", es_waf(WAF_REAL) is True)
v, _ = leer(WAF_REAL)
chequear("y nunca se lee como deposito hecho", v != "ok", v)

print("\n=== Y no se confunde con cualquier otro HTML ===")
# La distincion importa: al challenge se lo REINTENTA (prueba que la request no
# llego al backend, asi que no hay riesgo de depositar dos veces); al resto no.
chequear("una pagina de login NO es challenge",
         es_waf("<html><body><form id=login></form></body></html>") is False)
chequear("un JSON valido tampoco", es_waf('{"status":0}') is False)
chequear("ni una respuesta vacia", es_waf("") is False)
chequear("la otra firma (noscript + refresh) tambien cuenta",
         es_waf('<html><head><noscript><meta http-equiv="refresh" content="0; url=/abc"></noscript>') is True)


print("\n---------------------------------------")
print("%d OK, %d fallas" % (ok, fallas))
sys.exit(0 if fallas == 0 else 1)
