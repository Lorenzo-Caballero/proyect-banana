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
fn = next(n for n in arbol.body
          if isinstance(n, ast.FunctionDef) and n.name == 'leer_respuesta_deposito')
ns = {'json': json}
exec(compile(ast.Module(body=[fn], type_ignores=[]), '<t>', 'exec'), ns)
leer = ns['leer_respuesta_deposito']

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

print("\n---------------------------------------")
print("%d OK, %d fallas" % (ok, fallas))
sys.exit(0 if fallas == 0 else 1)
