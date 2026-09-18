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
        'WAF_ESPERAS_S': [0, 0, 0],   # sin dormir: el test mide intentos, no relojes
        # El contador de challenges de la pasada, que leer_json incrementa para
        # que salud_colector.php sepa cuanto nos esta peleando el WAF.
        'PASADA': {'challenges': 0},
        'time': type('t', (), {'sleep': staticmethod(
            lambda s: dormido.__setitem__('s', dormido['s'] + s))}),
        '_despejar_waf': lambda ctx: (setattr(ctx, 'despejes', ctx.despejes + 1), True)[1],
        'log': _Log(),
    }
    exec(compile(ast.Module(body=[fn], type_ignores=[]), '<t>', 'exec'), ns)
    global ns_ultimo
    ns_ultimo = ns
    try:
        d = ns['leer_json'](panel, 'http://x', 'prueba')
        return panel, d, None
    except Exception as e:
        return panel, None, e


ns_ultimo = {}


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
# ya tiene, pero no conseguir una nueva. Eso solo lo hace la pagina. Va en el
# ANTEULTIMO intento: recargar cuesta ~3 s y en la unica medicion que llego
# hasta ahi no despejo nada, asi que primero se deja pasar la rafaga.
panel, d, err = correr(['waf', 'waf', 'waf', 'ok'], intentos=4)
chequear('el cuarto intento sale', err is None and d == {'dato': 'ok'})
chequear('y antes se recargo la pagina del panel UNA vez', panel.despejes == 1,
         'despejes=%s' % panel.despejes)
panel, d, err = correr(['waf', 'ok'], intentos=4)
chequear('pero una rafaga corta no paga esa recarga', panel.despejes == 0,
         'son 3 s y el caso normal se resuelve solo esperando')

print('\n=== 4. Si el WAF no afloja, se rinde (no queda girando) ===')
panel, d, err = correr(['waf'] * 9, intentos=4)
chequear('levanta DesafioWAF', isinstance(err, DesafioWAF), 'err=%r' % err)
chequear('despues de exactamente 4 intentos', panel.pedidos == 4,
         'pedidos=%s -- de mas se come el minuto del cron' % panel.pedidos)

# Y que quede contado: es lo que salud_colector.php usa para saber cuanto nos
# esta peleando el WAF, y lo que se ve en salud_bot.php sin entrar al VPS.
chequear('cada challenge queda contado para el indicador de salud',
         ns_ultimo.get('PASADA', {}).get('challenges', 0) == 4,
         'challenges=%s' % ns_ultimo.get('PASADA', {}).get('challenges'))

print('\n=== 4b. La espera CRECE, que es lo que deja pasar la rafaga ===')
# El 18/09 se bajo a una espera fija de 0,5 s razonando que "el challenge es por
# request y la siguiente pasa". El primer barrido con ese valor se quedo sin
# intentos en la pagina 1, con tres challenges en cinco segundos: se resolvian
# en el segundo intento POR la espera, no a pesar de ella.
esperas = [n for n in ast.walk(arbol)
           if isinstance(n, ast.Assign)
           and any(getattr(x, 'id', '') == 'WAF_ESPERAS_S' for x in n.targets)]
chequear('las esperas son una lista, no un numero fijo', len(esperas) == 1)
valores = [v.value for v in esperas[0].value.elts] if esperas else []
chequear('y cada una es mas larga que la anterior',
         len(valores) >= 3 and all(valores[i] < valores[i + 1]
                                   for i in range(len(valores) - 1)),
         'valores=%s' % valores)
chequear('la primera es la que se midio funcionando (1,5 s)',
         bool(valores) and valores[0] == 1.5,
         'con 0,5 s se quedo sin intentos en el primer barrido')

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


# ---------------------------------------------------------------------------
print('\n=== 8. El barrido tiene presupuesto de tiempo ===')
# MEDIDO EL 18/09/2026, primera hora con el reintento puesto: el WAF desafia
# cada 5-7 paginas, y con eso el barrido de 62 paginas paso de 53 a 68
# segundos. El cron corre cada minuto con `flock -w 45`, o sea que una pasada
# de mas de ~105 s le hace perder el turno a la siguiente -- y en esa siguiente
# van las cargas y los retiros, que es la plata.
f = next(n for n in arbol.body
         if isinstance(n, ast.FunctionDef) and n.name == '_usuarios_paginas')
fuente_fn = ast.get_source_segment(fuente, f) or ''
chequear('corta si se pasa del presupuesto', 'USUARIOS_MAX_SEG' in fuente_fn,
         'sin esto un dia peor de WAF le come la pasada a las cargas')
chequear('y guarda lo que alcanzo a leer',
         'completo = False' in fuente_fn and 'break' in fuente_fn)

print('\n=== 9. Y retoma donde quedo ===')
# Sin esto se leerian siempre las mismas primeras 1.500 filas y los ultimos
# --los jugadores mas nuevos, justo los que importan-- no se espejarian nunca.
chequear('arranca donde termino el anterior', '_pagina_inicial()' in fuente_fn)
chequear('y al llegar al final vuelve a cero',
         '_guardar_pagina(0)' in fuente_fn,
         'si no, las primeras paginas se quedan sin leer para siempre')

# Las dos funciones de la marca se corren de verdad: son tres lineas y un
# archivo, pero si se equivocan el espejo se queda mirando media tabla.
import tempfile, time as _t
ns2 = {'os': os, 'time': _t}
for nombre in ('_pagina_inicial', '_guardar_pagina'):
    g = next(n for n in arbol.body
             if isinstance(n, ast.FunctionDef) and n.name == nombre)
    exec(compile(ast.Module(body=[g], type_ignores=[]), '<t>', 'exec'), ns2)
marca = os.path.join(tempfile.gettempdir(), 'gp_test_pagina')
ns2['_USUARIOS_PAGINA'] = marca
if os.path.exists(marca):
    os.remove(marca)
chequear('sin marca, arranca en la pagina 0', ns2['_pagina_inicial']() == 0)
ns2['_guardar_pagina'](37)
chequear('guarda y devuelve donde quedo', ns2['_pagina_inicial']() == 37)
ns2['_guardar_pagina'](0)
chequear('un barrido completo la vuelve a cero', ns2['_pagina_inicial']() == 0)
# Una marca olvidada no puede dejar las primeras paginas sin leer para siempre.
ns2['_guardar_pagina'](50)
os.utime(marca, (_t.time() - 3600, _t.time() - 3600))
chequear('una marca de hace una hora se ignora', ns2['_pagina_inicial']() == 0,
         'si no, un corte raro deja media tabla sin espejar para siempre')
os.remove(marca)

print('\n---------------------------------------')
print('%d OK, %d fallas' % (ok, fallas))
sys.exit(1 if fallas else 0)
