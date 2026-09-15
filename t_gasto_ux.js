/**
 * t_gasto_ux.js — Cargar el gasto de pauta tocando la celda.
 *
 * POR QUÉ CAMBIÓ (Nahuel, 15/09/2026): "me resulta demasiado confuso cómo es
 * que se meten y editan los gastos publicitarios".
 *
 * Tenía razón, y el código lo delataba: había TRES comentarios distintos
 * avisando que "cargarle el gasto al equivocado es fácil y no se nota hasta que
 * los números no cierran". Cuando hay que advertir lo mismo tres veces, el
 * problema no es la advertencia.
 *
 * Lo que había: para cargar el gasto de un día se abría un modal que volvía a
 * pedir la FECHA —que vos ya habías elegido tocando la fila— y que le cargaba
 * la plata a la campaña de la solapa abierta, no a la de la fila. Para una
 * semana eran seis idas y vueltas.
 *
 * Lo que hay: se escribe en la celda. Enter guarda, Esc cancela.
 *
 * ESTO ESCRIBE PLATA, así que lo que se prueba es sobre todo lo que NO tiene
 * que pasar: que un campo vacío no borre, que un monto inválido no viaje, que
 * no se mande nada si el número no cambió, y que el destino salga de la
 * campaña correcta.
 *
 * Se EXTRAE la función de landing/crm.html, no se copia: una copia deja de
 * proteger justo cuando alguien toca el original.
 *
 * SECCIÓN 6 — la ventana propia. Nahuel: "si tengo que cambiar el filtro...
 * veo datos diferentes". La tabla colgaba del período de arriba, que es el
 * mismo que manda el embudo, el CPA y el ROAS: para corregir el gasto de un
 * martes había que mover el reporte entero. Ahora tiene su ventana.
 *
 *     node t_gasto_ux.js
 */
const fs = require("fs");
const path = require("path");

const SRC = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8")
            .split("\r\n").join("\n");

let ok = 0, fallas = 0;
function chequear(que, cond, detalle) {
  if (cond) { ok++; console.log("  OK    " + que); }
  else { fallas++; console.log("  FALLA " + que + (detalle ? " -- " + detalle : "")); }
}

const DESDE = "  function pbEditarGastoEnLinea(btn, dia){";
const i = SRC.indexOf(DESDE);
if (i < 0) { console.error("No encontré pbEditarGastoEnLinea() en crm.html"); process.exit(1); }
const j = SRC.indexOf("\n  }\n", i);
if (j < 0) { console.error("No encontré el cierre de pbEditarGastoEnLinea()"); process.exit(1); }
const FUENTE = SRC.slice(i, j + 4);

/* ------------------------------------------------------------------ */
/* Un DOM de juguete: la celda, su input, y nada más. */
function escenario(opciones) {
  const o = opciones || {};
  const enviados = [], avisos = [], recargas = [];

  const input = {
    value: o.valorInicial === undefined ? "" : o.valorInicial,
    disabled: false, foco: 0, teclas: {},
    focus() { this.foco++; }, select() {},
    addEventListener(ev, fn) { this.teclas[ev] = fn; },
  };
  const td = {
    _html: "",
    set innerHTML(v) { this._html = v; },
    get innerHTML() { return this._html; },
    querySelector() { return input; },
  };
  const btn = { parentElement: td };

  const ctx = {
    // Campaña seleccionada: una landing, o (si es null) el publicista activo.
    pbLandingSel: o.landing || null,
    pbActivoId: o.publicistaId === undefined ? 7 : o.publicistaId,
    pbPublicistaActivo: () => o.publicista || { nombre: "Pub Uno" },
    pbPostJson: async (cuerpo) => { enviados.push(cuerpo); return o.respuesta || { ok: true }; },
    toast: (t) => avisos.push(t),
    money: (n) => "$" + Number(n || 0).toLocaleString("es-AR"),
    esc: (s) => String(s),
    ico: () => "",
    pbDiaLabel: (f) => String(f),
    pbCargarDiaPorDia: () => recargas.push("dias"),
    pbCargarEmbudo: () => recargas.push("embudo"),
    pbCargarGastoTodo: () => recargas.push("todo"),
    setTimeout: (fn) => fn(),      // el blur no espera en el test
  };

  const nombres = Object.keys(ctx);
  const fabricar = new Function(...nombres,
    "let pbEditando = null;\n" + FUENTE + "\nreturn pbEditarGastoEnLinea;");
  const editar = fabricar(...nombres.map((k) => ctx[k]));

  return {
    abrir: (dia) => editar(btn, dia),
    input, td, enviados, avisos, recargas,
    tecla: (k) => input.teclas.keydown({ key: k, preventDefault() {} }),
    salir: () => input.teclas.blur && input.teclas.blur(),
  };
}

const DIA = { fecha: "2026-09-13", gasto: 0 };

async function correr() {
  console.log("\n=== 1. Cargar el gasto de un día ===");
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ ...DIA });
    chequear("la celda se vuelve editable", /input/.test(e.td.innerHTML));
    chequear("y el foco va al campo", e.input.foco === 1);
    chequear("con la ayuda a la vista", /Enter guarda/.test(e.td.innerHTML), e.td.innerHTML);

    e.input.value = "22500";
    await e.tecla("Enter");
    chequear("Enter manda el gasto", e.enviados.length === 1, JSON.stringify(e.enviados));
    const g = e.enviados[0] || {};
    chequear("con el monto tipeado", g.monto === 22500, String(g.monto));
    chequear("y con la fecha DE LA FILA, no una elegida a mano",
             g.fecha === "2026-09-13", String(g.fecha));
    chequear("y a la landing que se está viendo", g.landing === "bono50", JSON.stringify(g));
  }

  console.log("\n=== 2. Lo que NO tiene que pasar ===");
  /* Acá se escribe plata: importa más lo que no viaja que lo que viaja. */
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ fecha: "2026-09-13", gasto: 22500 });
    e.input.value = "";
    await e.tecla("Enter");
    chequear("un campo VACÍO no borra el gasto cargado", e.enviados.length === 0,
             JSON.stringify(e.enviados));
  }
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ ...DIA });
    e.input.value = "abc";
    await e.tecla("Enter");
    chequear("un monto inválido no viaja", e.enviados.length === 0);
    chequear("y lo dice", e.avisos.some(a => /válido/i.test(a)), JSON.stringify(e.avisos));
  }
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ ...DIA });
    e.input.value = "-100";
    await e.tecla("Enter");
    chequear("un monto negativo tampoco", e.enviados.length === 0);
  }
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ fecha: "2026-09-13", gasto: 22500 });
    e.input.value = "22500";
    await e.tecla("Enter");
    chequear("si el número no cambió, no se molesta al server", e.enviados.length === 0);
  }
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ fecha: "2026-09-13", gasto: 22500 });
    e.input.value = "999";
    await e.tecla("Escape");
    chequear("Esc cancela sin mandar nada", e.enviados.length === 0);
    chequear("y repinta la tabla para volver al valor de antes",
             e.recargas.includes("dias"));
  }

  console.log("\n=== 3. Guardar al salir del campo ===");
  /* El error clásico: escribir el número, irse a otra fila y perderlo sin que
     nada avise. Al salir se guarda. */
  {
    const e = escenario({ landing: { slug: "bono50", nombre: "Bono 50" } });
    e.abrir({ ...DIA });
    e.input.value = "8000";
    await e.salir();
    chequear("salir del campo guarda", e.enviados.length === 1, JSON.stringify(e.enviados));
    chequear("y refresca el embudo, que es donde viven el CPA y el ROAS",
             e.recargas.includes("embudo"));
  }

  console.log("\n=== 4. El destino sale de la campaña abierta ===");
  {
    const e = escenario({ landing: null, publicistaId: 42 });
    e.abrir({ ...DIA });
    e.input.value = "5000";
    await e.tecla("Enter");
    const g = e.enviados[0] || {};
    chequear("sin landing, va al publicista activo", g.publicista_id === 42, JSON.stringify(g));
    chequear("y NO manda landing (el backend rechaza los dos juntos)",
             !("landing" in g), JSON.stringify(g));
  }

  console.log("\n=== 5. Si el server rechaza, no se miente ===");
  {
    const e = escenario({
      landing: { slug: "bono50", nombre: "Bono 50" },
      respuesta: { ok: false, error: "fecha futura" },
    });
    e.abrir({ ...DIA });
    e.input.value = "1000";
    await e.tecla("Enter");
    chequear("se muestra el error del server",
             e.avisos.some(a => /fecha futura/.test(a)), JSON.stringify(e.avisos));
    chequear("y no se anuncia un guardado que no pasó",
             !e.avisos.some(a => /Gasto de/.test(a)), JSON.stringify(e.avisos));
  }

  console.log("\n=== 6. La ventana propia de la tabla de gasto ===");
  /* LA TABLA NO SIGUE AL FILTRO DE ARRIBA. Se extrae pbRangoGasto() de
     crm.html y se la corre con distintos tamaños de ventana. */
  {
    const iF = SRC.indexOf("  function pbFmtFecha(d){");
    const jF = SRC.indexOf("\n  }\n", iF);
    const iR = SRC.indexOf("  function pbRangoGasto(){");
    const jR = SRC.indexOf("\n  }\n", iR);
    if (iF < 0 || iR < 0) {
      console.error("No encontré pbFmtFecha()/pbRangoGasto() en crm.html");
      process.exit(1);
    }
    const rango = (dias) => new Function(
      "pbDiasGasto",
      SRC.slice(iF, jF + 4) + "\n" + SRC.slice(iR, jR + 4) + "\nreturn pbRangoGasto();"
    )(dias);

    const hoy = new Date();
    const p = (n) => String(n).padStart(2, "0");
    const fmt = (d) => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;

    const r10 = rango(10);
    chequear("termina HOY", r10.hasta === fmt(hoy), r10.hasta);
    const esperado10 = new Date(); esperado10.setDate(esperado10.getDate() - 9);
    chequear("con 10 días arranca 9 días atrás (10 contando hoy)",
             r10.desde === fmt(esperado10), r10.desde + " vs " + fmt(esperado10));

    const r20 = rango(20);
    const esperado20 = new Date(); esperado20.setDate(esperado20.getDate() - 19);
    chequear('"Ver más días" estira hacia atrás, no hacia adelante',
             r20.desde === fmt(esperado20) && r20.hasta === r10.hasta,
             JSON.stringify(r20));

    /* El caso que importa: la ventana NO depende de nada del reporte. Si
       alguna vez vuelve a leer pbRangoActual, esto lo agarra -- la función se
       evalúa sin esa variable en el scope, así que tiraría ReferenceError. */
    let sano = true;
    try { rango(30); } catch (e) { sano = false; }
    chequear("no lee el período del reporte (ni existe en su scope)", sano);
  }

  console.log("\n---------------------------------------");
  console.log(`${ok} OK, ${fallas} fallas`);
  process.exit(fallas ? 1 : 0);
}

correr();
