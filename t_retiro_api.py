# -*- coding: utf-8 -*-
"""t_retiro_api.py -- interpretar la respuesta de un RETIRO del panel.

Es la direccion mas delicada: se le SACA plata al jugador. Un falso 'hecha' le
descuenta algo que nunca salio de su saldo; un reintento de mas se lo descuenta
dos veces. Por eso el criterio es el mas conservador de todos: solo se da por
hecho un status 0 explicito, y cualquier otra cosa va a 'revisar' -- que no
descuenta ni devuelve nada y lo mira una persona.

La UNICA excepcion es el challenge del WAF: ahi SI se sabe que no paso nada (lo
contesto el WAF, la request no llego al backend), asi que se puede reintentar.

    python t_retiro_api.py
"""
import ast, io, json, os, sys

fuente = io.open(os.path.join('colector', 'aprobar_cargas.py'), encoding='utf-8').read()
fn = next(n for n in ast.parse(fuente).body
          if isinstance(n, ast.FunctionDef) and n.name == 'retirar_del_jugador')

class _Resp:
    def __init__(self, status, cuerpo): self.status, self._c, self.ok = status, cuerpo, 200 <= status < 300
    def text(self): return self._c
class _Ctx:
    def __init__(self, r): self.request = self
    def post(self, *a, **k): return _Ctx.resp

ns = {'json': json, 'PANEL_API': 'https://x/api', 'OP_RETIRO': 1}
exec(compile(ast.Module(body=[fn], type_ignores=[]), '<t>', 'exec'), ns)

def evaluar(status, cuerpo):
    class C:
        class request:
            @staticmethod
            def post(*a, **k): return _Resp(status, cuerpo)
    return ns['retirar_del_jugador'](C, 123, 100)

ok = fallas = 0
def chequear(q, cond, det=''):
    global ok, fallas
    if cond: ok += 1;     print("  OK    " + q)
    else:    fallas += 1; print("  FALLA " + q + "   " + str(det))

print("\n=== El retiro real que capturo Nahuel ===")
REAL = json.dumps({"status": 0, "result": {"transfer_detail": {
    "from_user_id": 38728668, "to_user_id": 20284777, "amount": 1.0}}, "error_message": None})
e, d = evaluar(200, REAL)
chequear("status 0 -> hecha", e == "hecha", e)

print("\n=== Todo lo demas NO descuenta ===")
for status, cuerpo, esperado, etiq in [
    (200, json.dumps({"status": 501, "error_message": "sin saldo"}), "revisar", "la plataforma dijo que no"),
    (200, "",                                    "revisar",    "respuesta vacia"),
    (200, "no soy json",                         "revisar",    "no es JSON"),
    (200, json.dumps({"result": {}}),            "revisar",    "JSON sin status"),
    (500, "boom",                                "revisar",    "error del server"),
    (401, "no autorizado",                       "revisar",    "sesion vencida"),
    (429, "calmate",                             "revisar",    "rate limit"),
]:
    e, _ = evaluar(status, cuerpo)
    chequear("%-26s -> %s" % (etiq, esperado), e == esperado, e)

print("\n=== El WAF es la unica excepcion: ahi SI se puede reintentar ===")
WAF = '<!DOCTYPE html><html><head><noscript><meta http-equiv="refresh" content="0; url=/exhkqyad">'
e, _ = evaluar(200, WAF)
chequear("challenge -> reintentar (no llego al backend)", e == "reintentar", e)
e, _ = evaluar(200, '<html><body>Access denied</body></html>')
chequear("otro HTML NO se reintenta: no se sabe", e == "revisar", e)

print("\n=== Nunca se inventa un 'hecha' ===")
malos = [(200, json.dumps({"status": 1})), (200, json.dumps({"status": "ok"})),
         (200, "[]"), (204, ""), (200, json.dumps({"status": None}))]
chequear("ninguna respuesta ambigua da 'hecha'",
         all(evaluar(s, c)[0] != "hecha" for s, c in malos))

print("\n---------------------------------------")
print("%d OK, %d fallas" % (ok, fallas))
sys.exit(0 if fallas == 0 else 1)
