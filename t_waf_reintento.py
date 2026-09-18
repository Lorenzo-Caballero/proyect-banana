# -*- coding: utf-8 -*-
"""t_waf_reintento.py -- que un challenge del WAF no cueste 5 o 15 minutos.

EL PROBLEMA (medido el 18/09/2026 en produccion). El WAF (ServicePipe) desafia
de a ratos y contesta 200 con HTML. Cada vez que eso pasaba, la tarea entera se
abandonaba y se reintentaba "en la proxima vuelta" -- que para el espejo de
saldos son 5 minutos y para el libro 15. En una hora de log:

    20:14:14  espejo de saldos: challenge del WAF
    20:20:08  espejo de saldos: challenge del WAF
    20:25:14  espejo de saldos: challenge del WAF
    20:25:15  saldos activos:   challenge del WAF

TRES de seis pasadas muertas. Un jugador recien creado estuvo veinte minutos
sin aparecer en el CRM y el operador tuvo que cargarle a mano.

POR QUE REINTENTAR ES SEGURO, que es lo unico que hace esto posible: un
challenge PRUEBA que la request no llego al backend -- la contesto el WAF, que
esta delante. Repetirla no puede duplicar nada.

Y POR QUE SOLO EN LECTURAS, que es lo que este archivo vigila de verdad: en un
aprobar, un rechazar o un retiro, una respuesta ilegible NO prueba que no haya
pasado nada, y reintentar a ciegas paga dos veces. El test posicional del final
falla si alguien envuelve una escritura con esto.

    python t_waf_reintento.py
"""
import ast, io, os, sys

RUTA = os.path.join('colector', 'aprobar_cargas.py')
fuente = io.open(RUTA, encoding='utf-8').read()
arbol = ast.parse(fuente)

ok = 0
fallas = 0


def chequear(que, cond, detalle=''):
    global ok, fallas
    if cond:
        ok += 1
        print('  OK    %s' % que)
    else:
        fallas += 1
        print('  FALLA %s   %s' % (que, detalle))


# ---------------------------------------------------------------------------
# Se saca leer_json del archivo y se corre de verdad, con un panel de mentira.
# ---------------------------------------------------------------------------
fn = next(n for n in arbol.body
          if isinstance(n, ast.FunctionDef) and n.name == 'leer_json')


class DesafioWAF(RuntimeError):
    pass


class Panel:
    """Un panel que contesta lo que se le diga, y cuenta cuantas veces le
    preguntaron. `respuestas` es una lista: 'waf' es un challenge."""

    def __init__(self, respuestas):
        self.respuestas = list(respuestas)
        self.pedidos = 0
        self.despejes = 0

    # lo que aprobar_cargas llama como ctx.request.get(...)
    @property
    def request(self):
        return self

    def get(self, url, **kw):
        self.pedidos += 1
        r = self.respuestas.pop(0) if self.respuestas else 'ok'
        if r == 'waf':
            raise DesafioWAF('el panel devolvio HTML (challenge del WAF)')
        return {'dato': r}


def correr(respuestas, intentos=3):
    panel = Panel(respuestas)
    dormido = {'s': 0.0}

    class _Log:
        def info(self, *a, **k): pass
        def warning(self, *a, **k): pass

    ns = {
        '_json': lambda x: x,
        'DesafioWAF': DesafioWAF,
        'WAF_INTENTOS': intentos,
        'WAF_ESPERA_S': 0,
        'time': type('t', (), {'sleep': staticmethod(
            lambda s: dormido.__setitem__('s', dormido['s'] + s))}),
        '_despejar_waf': lambda ctx: (setattr(ctx, 'despejes', ctx.despejes + 1), True)[1],
        'log': _Log(),
    }
    exec(compile(ast.Module(body=[fn], type_ignores=[]), '<t>', 'exec'), ns)
    try:
        d = ns['leer_json'](panel, 'http://x', 'prueba')
        return panel, d, None
    except Exception as e:
        return panel, None, e


print('\n=== 1. Lo normal no cambia ===')
panel, d, err = correr(['ok'])
chequear('sin challenge, una sola llamada', panel.pedidos == 1 and err is None,
         'pedidos=%s' % panel.pedidos)

print('\n=== 2. Un challenge suelto se reintenta y sale ===')
panel, d, err = correr(['waf', 'ok'])
chequear('el segundo intento devuelve el dato', err is None and d == {'dato': 'ok'},
         'err=%s d=%s' % (err, d))
chequear('y fueron dos llamadas, no una', panel.pedidos == 2, 'pedidos=%s' % panel.pedidos)
chequear('el primer reintento NO recarga la pagina', panel.despejes == 0,
         'recargar el panel son ~3 s; el challenge suelto no los necesita')

print('\n=== 3. Si insiste, se despeja con el navegador ===')
# `ctx.request` no ejecuta JavaScript: puede llevar la cookie de clearance que
# ya tiene, pero no conseguir una nueva. Eso solo lo hace la pagina.
panel, d, err = correr(['waf', 'waf', 'ok'])
chequear('el tercer intento sale', err is None and d == {'dato': 'ok'})
chequear('y antes se recargo la pagina del panel', panel.despejes == 1,
         'despejes=%s' % panel.despejes)

print('\n=== 4. Si el WAF no afloja, se rinde (no queda girando) ===')
panel, d, err = correr(['waf', 'waf', 'waf', 'waf', 'waf'])
chequear('levanta DesafioWAF', isinstance(err, DesafioWAF), 'err=%r' % err)
chequear('despues de exactamente 3 intentos', panel.pedidos == 3,
         'pedidos=%s -- de mas se come el minuto del cron' % panel.pedidos)

# ---------------------------------------------------------------------------
print('\n=== 5. NUNCA una escritura ===')
# La regla que sostiene todo esto. Escrito como test y no como comentario
# porque el dia que alguien "mejore" el reintento envolviendo un aprobar, el
# precio es una carga aprobada dos veces.
ESCRITURAS = ['aprobar', 'rechazar', 'retirar_del_jugador', 'fijar_bono', 'confirmar']
for nombre in ESCRITURAS:
    f = next((n for n in arbol.body
              if isinstance(n, ast.FunctionDef) and n.name == nombre), None)
    if f is None:
        chequear('existe %s()' % nombre, False, 'se renombro: revisar este test')
        continue
    usa = any(isinstance(n, ast.Name) and n.id == 'leer_json' for n in ast.walk(f))
    chequear('%s() no reintenta sola' % nombre, not usa,
             'una respuesta ilegible NO prueba que no haya pasado nada')

# Y del otro lado: que las lecturas SI lo usen, o este archivo no prueba nada.
print('\n=== 6. Y si en todas las lecturas ===')
for nombre, donde in [('_usuarios_paginas', 'el espejo de saldos'),
                      ('_libro_paginas', 'el libro de operaciones'),
                      ('refrescar_saldos_activos', 'el saldo de los que hablan ahora'),
                      ('revisar_stock', 'nuestro stock de fichas')]:
    f = next((n for n in arbol.body
              if isinstance(n, ast.FunctionDef) and n.name == nombre), None)
    usa = f is not None and any(isinstance(n, ast.Name) and n.id == 'leer_json'
                                for n in ast.walk(f))
    chequear('%s sobrevive un challenge' % donde, usa)

# Las solicitudes llevan el reintento a mano (conservan el chequeo de r.ok),
# asi que se busca distinto.
f = next(n for n in arbol.body
         if isinstance(n, ast.FunctionDef) and n.name == 'traer_solicitudes')
chequear('las cargas pendientes tambien',
         any(isinstance(n, ast.Name) and n.id == 'WAF_INTENTOS' for n in ast.walk(f)),
         'es la lectura de la plata: un challenge le cuesta un minuto al jugador')

# ---------------------------------------------------------------------------
print('\n=== 7. El espejo reintenta por PAGINA, no toda la lista ===')
# Son ~62 paginas y casi un minuto de trabajo: si el challenge pega en la 50,
# volver a empezar no entra en el minuto del cron.
f = next(n for n in arbol.body
         if isinstance(n, ast.FunctionDef) and n.name == '_usuarios_paginas')
dentro_del_while = any(
    any(isinstance(x, ast.Name) and x.id == 'leer_json' for x in ast.walk(n))
    for n in ast.walk(f) if isinstance(n, ast.While))
chequear('la llamada con reintento esta adentro del bucle', dentro_del_while,
         'envolver la funcion entera cuesta las 50 paginas que ya salieron bien')

print('\n---------------------------------------')
print('%d OK, %d fallas' % (ok, fallas))
sys.exit(1 if fallas else 0)
