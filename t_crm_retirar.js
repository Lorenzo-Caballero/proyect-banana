/**
 * t_crm_retirar.js — El modal que mueve plata en el CRM.
 *
 * Tres cosas, todas del 15/09/2026 y todas del mismo reporte de Nahuel:
 * "revisá el retiro... puse retirar 4280 y se le retiraron 4000 parece. Y
 * agregá el botón 'retirar todo'".
 *
 *   1. EL MONTO QUE SE MANDA ES EL QUE SE TIPEÓ. Si el front recortara,
 *      redondeara o se comiera un dígito, este sería el primer lugar donde
 *      mirar. Queda probado que no: 4280 sale 4280.
 *   2. "RETIRAR TODO" llena el campo con el saldo CRUDO, y solo aparece en el
 *      retiro y con saldo > 0. Llenarlo desde el texto de la tarjeta ("$4.280")
 *      es exactamente como se cuela un error de un cero.
 *   3. EL BOTÓN NUNCA DICE LO CONTRARIO DE LO QUE HACE. Ya se había arreglado
 *      al ABRIR el modal y volvió a aparecer en el camino de vuelta: al
 *      terminar la request el botón se reseteaba a "Cargar" siempre, también
 *      en la pantalla titulada "Retirar saldo".
 *
 * Se EXTRAE el código de landing/crm.html en vez de copiarlo: una copia prueba
 * la copia. Si alguien renombra las funciones, el test avisa que no las
 * encuentra -- que es justo lo que tiene que pasar.
 *
 *     node t_crm_retirar.js
 */
const fs = require("fs");
const path = require("path");

/* A LF. El archivo se edita en Windows y viaja con CRLF; los marcadores de
   abajo buscan saltos de línea sueltos, que con CRLF no matchean -- y el test
   se caía diciendo que no encuentra una función que está ahí. */
const SRC = fs.readFileSync(path.join(__dirname, "landing", "crm.html"), "utf8")
            .split("\r\n").join("\n");

let ok = 0, fallas = 0;
function chequear(que, cond, detalle) {
  if (cond) { ok++; console.log("  OK    " + que); }
  else { fallas++; console.log("  FALLA " + que + (detalle ? " -- " + detalle : "")); }
}

/** Corta desde `desde` hasta la primera aparición de `hasta` (incluida). */
function tajada(desde, hasta) {
  const i = SRC.indexOf(desde);
  if (i < 0) { console.error("No encontré en crm.html:\n  " + desde); process.exit(1); }
  const j = SRC.indexOf(hasta, i);
  if (j < 0) { console.error("No encontré el cierre de:\n  " + desde); process.exit(1); }
  return SRC.slice(i, j + hasta.length);
}

const FUENTE_ABRIR = tajada("  function abrirModal(accion, titulo, ganamos){", "\n  }\n");
const FUENTE_OK    = tajada('  $("#mOk").addEventListener("click", async ()=>{', "\n  });\n");

/* ------------------------------------------------------------------ */
/* Un DOM de juguete: solo lo que el modal toca. */
function escenario(opciones) {
  const o = opciones || {};
  const nodos = {};
  let handler = null;

  function nodo(id) {
    if (!nodos[id]) {
      nodos[id] = {
        id, value: "", textContent: "", disabled: false, onclick: null,
        style: {}, foco: 0,
        focus() { this.foco++; },
        addEventListener(_ev, fn) { handler = fn; },
        classList: {
          _set: new Set(),
          add(c) { this._set.add(c); },
          remove(c) { this._set.delete(c); },
          toggle(c, on) { on ? this._set.add(c) : this._set.delete(c); },
          contains(c) { return this._set.has(c); },
        },
      };
    }
    return nodos[id];
  }

  const enviados = [], avisos = [];
  const ctx = {
    $: nodo,
    st: {
      usuario: o.usuario === undefined ? "holajuan969" : o.usuario,
      convId: 7, accion: "",
      saldo: o.saldo === undefined ? 0 : o.saldo,
      saldoHace: o.saldoHace === undefined ? 0 : o.saldoHace,
      retirosAbiertos: o.retirosAbiertos || [],
    },
    money: (n) => "$" + Number(n || 0).toLocaleString("es-AR"),
    nf: { format: (n) => Number(n || 0).toLocaleString("es-AR") },
    toast: (t) => avisos.push(t),
    abrir: () => {},
    setTimeout: (fn) => fn(),
    SALDO_VIEJO_SEG: 600,
    jpost: async (cuerpo) => { enviados.push(cuerpo); return o.respuesta || { ok: true }; },
  };

  // Las dos tajadas se evalúan juntas: la segunda REGISTRA el handler del botón
  // al correr, igual que en la página.
  const nombres = Object.keys(ctx);
  const abrirModal = new Function(
    ...nombres, `${FUENTE_ABRIR}\n${FUENTE_OK}\nreturn abrirModal;`
  )(...nombres.map((k) => ctx[k]));

  return {
    abrir: (accion, titulo, ganamos) => abrirModal(accion, titulo, ganamos),
    click: () => handler(),          // devuelve la promesa del handler async
    nodo, enviados, avisos, st: ctx.st,
  };
}

async function correr() {
  console.log("\n=== 1. El monto que se manda es el que se tipeó ===");
  /* EL CASO DE NAHUEL. Si el 280 se hubiera perdido acá, se vería en este test:
     el modal manda lo que hay en el campo, sin tocarlo. */
  {
    const e = escenario({ saldo: 4280 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    e.nodo("#mMonto").value = "4280";
    await e.click();
    chequear("pide retirar 4280 y sale 4280",
             e.enviados.length === 1 && e.enviados[0].monto === 4280,
             JSON.stringify(e.enviados));
    chequear("y con la acción de retirar, no la de cargar",
             e.enviados[0] && e.enviados[0].accion === "retirar_saldo");
  }

  console.log("\n=== 2. 'Retirar todo' ===");
  {
    const e = escenario({ saldo: 4280.75 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    const btn = e.nodo("#mTodo");
    chequear("aparece en el modal de retirar", btn.style.display === "");
    chequear("y dice cuánto es", /4\.?280/.test(btn.textContent), btn.textContent);

    btn.onclick();
    chequear("al tocarlo llena el campo con el saldo CRUDO, sin decimales",
             e.nodo("#mMonto").value === 4280, String(e.nodo("#mMonto").value));

    /* NO DISPARA EL RETIRO. Llena el campo y listo: el operador todavía tiene
       que apretar "Retirar", y puede corregir el número antes. Un botón que
       además mandara sería un retiro a un toque. */
    chequear("pero no manda nada solo", e.enviados.length === 0);
  }
  {
    const e = escenario({ saldo: 5000 });
    e.abrir("cargar_saldo", "Cargar saldo", true);
    chequear("en el modal de CARGAR no aparece", e.nodo("#mTodo").style.display === "none");
  }
  {
    const e = escenario({ saldo: 0 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("con saldo 0 tampoco (sería un botón que no hace nada)",
             e.nodo("#mTodo").style.display === "none");
  }

  console.log("\n=== 3. La edad del saldo, al lado del botón ===");
  /* El botón invita a confiar en el número. `usuarios.balance` es un ESPEJO: si
     nadie lo leyó hace rato, "todo" ya no es todo. */
  {
    const e = escenario({ saldo: 4280, saldoHace: 3 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("recién leído: lo dice sin alarmar",
             /instantes/i.test(e.nodo("#mTodoAviso").textContent),
             e.nodo("#mTodoAviso").textContent);
  }
  {
    const e = escenario({ saldo: 4280, saldoHace: 3600 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("leído hace una hora: avisa que puede haber cambiado",
             /Ojo/.test(e.nodo("#mTodoAviso").textContent),
             e.nodo("#mTodoAviso").textContent);
  }
  {
    const e = escenario({ saldo: 4280, saldoHace: null });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("nunca leído: lo dice, en vez de callarlo",
             /nunca leímos/i.test(e.nodo("#mTodoAviso").textContent),
             e.nodo("#mTodoAviso").textContent);
  }

  console.log("\n=== 4. Ya tiene un pedido de retiro abierto ===");
  /* LAS DOS COLAS. Lo que el jugador pide adentro del juego vive en otra tabla
     y se ve en otra pantalla; abrir un segundo pedido sin ver el primero es
     como se termina pagando dos veces. El 15/09/2026: 4.280 al banco, 4.000
     sacados del juego, 280 dando vueltas. */
  {
    const e = escenario({ saldo: 4280,
      retirosAbiertos: [{ monto: 4000, estado: "pendiente", del_juego: true }] });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    const av = e.nodo("#mRetAbiertos");
    chequear("el aviso aparece", av.style.display === "", JSON.stringify(av.style));
    chequear("dice cuanto es el pedido que ya existe",
             /4\.?000/.test(av.textContent), av.textContent);
    chequear("y de donde salio", /en el juego/i.test(av.textContent), av.textContent);
    /* AVISA, NO BLOQUEA. A veces el segundo pedido es lo correcto (el primero
       quedo mal, o es otra cosa): la decision sigue siendo del operador. */
    chequear("pero NO bloquea: el boton sigue habilitado",
             e.nodo("#mOk").disabled === false);
  }
  {
    const e = escenario({ saldo: 4280,
      retirosAbiertos: [{ monto: 4000, estado: "pendiente", del_juego: true }] });
    e.abrir("cargar_saldo", "Cargar saldo", true);
    chequear("en el modal de CARGAR no se avisa (no viene al caso)",
             e.nodo("#mRetAbiertos").style.display === "none");
  }
  {
    const e = escenario({ saldo: 4280 });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("sin pedidos abiertos no hay cartel",
             e.nodo("#mRetAbiertos").style.display === "none");
  }

  console.log("\n=== 5. El botón nunca dice lo contrario de lo que hace ===");
  {
    const e = escenario({ saldo: 4280, respuesta: { ok: false, error: "no se pudo" } });
    e.abrir("retirar_saldo", "Retirar saldo", true);
    chequear("al abrirlo dice Retirar", e.nodo("#mOk").textContent === "Retirar");
    chequear("y va en rojo", e.nodo("#mOk").classList.contains("peligro"));

    e.nodo("#mMonto").value = "4280";
    await e.click();
    chequear("después de un retiro FALLIDO sigue diciendo Retirar",
             e.nodo("#mOk").textContent === "Retirar", e.nodo("#mOk").textContent);
    chequear("y queda habilitado para reintentar", e.nodo("#mOk").disabled === false);
  }
  {
    const e = escenario({ saldo: 0 });
    e.abrir("cargar_saldo", "Cargar saldo", true);
    e.nodo("#mMonto").value = "500";
    await e.click();
    chequear("y en el de cargar vuelve a decir Cargar",
             e.nodo("#mOk").textContent === "Cargar", e.nodo("#mOk").textContent);
  }

  console.log("\n---------------------------------------");
  console.log(`${ok} OK, ${fallas} fallas`);
  process.exit(fallas ? 1 : 0);
}

correr();
