/* App del funcionario: ingreso, calendario, "Pedir días", "Mis solicitudes" y "Mi perfil". */
(function () {
  'use strict';
  const { h, api, vaciar } = AUS;
  const $ = (id) => document.getElementById(id);
  const mostrarEl = (el, v) => el.classList.toggle('oculto', !v);
  const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
  let CFG = null;
  let SES = null;         // { persona, compartido } de la sesión iniciada
  let ULTIMA = null;      // última solicitud enviada (para la pantalla de confirmación)

  // ═══════════ Calendario ═══════════
  const C = { mes: null, vista: { tipo: 'turn' }, data: null, sel: null, listo: false };

  // Vista: «Equipo» (por defecto el propio), «Todos» o «Turnantes» (SPEC-v2 §6.1). Se recuerda en este dispositivo.
  function poblarVista() {
    const sel = $('c-vista');
    const equipos = CFG.equipos.filter((e) => e.tiene_diurnos);
    vaciar(sel).append(...equipos.map((e) => h('option', { value: e.id }, e.nombre)));
    const propio = SES.persona.jornada === 'diurno' ? SES.persona.equipo_id : null;
    const g = AUS.leer('aus_vista2', null) || {};
    const modo = ['equipo', 'todos', 'turn'].includes(g.modo) ? g.modo : (propio ? 'equipo' : 'turn');
    sel.value = equipos.some((e) => e.id === g.equipoId) ? g.equipoId : (propio || (equipos[0] || {}).id);
    document.querySelector('input[name="c-modo"][value="' + modo + '"]').checked = true;
    aplicarVista();
  }
  function aplicarVista() {
    const modo = document.querySelector('input[name="c-modo"]:checked').value;
    mostrarEl($('c-vista'), modo === 'equipo');
    C.vista = modo === 'equipo' ? { tipo: 'equipo', equipoId: +$('c-vista').value } : { tipo: modo };
    AUS.guardar('aus_vista2', { modo, equipoId: +$('c-vista').value });
  }

  async function cargarMes() {
    $('c-error').textContent = '';
    try { C.data = await api('calendario', { params: { mes: C.mes } }); pintarCal(false); }
    catch (e) { $('c-error').textContent = e.message; }
  }

  function pintarCal(scroll) {
    if (!C.data) return;
    $('c-titulo').textContent = AUS.mesTitulo(C.mes);
    AUS.calendario($('c-grid'), C.data, C.vista, { seleccion: C.sel, onSelect: (f) => { C.sel = f; pintarCal(true); } });
    vaciar($('c-leyenda')).append(AUS.leyenda(C.vista));
    const det = vaciar($('c-detalle'));
    if (C.sel && C.sel.startsWith(C.mes)) {
      const caja = AUS.detalleDia(C.sel, C.data, C.vista, false);
      if (C.sel >= AUS.hoyISO() && (C.vista.tipo !== 'equipo' || (!AUS.esFinde(C.sel) && !C.data.feriados[C.sel]))) {
        caja.append(h('p', null, h('a', { class: 'btn btn-primario', href: '#/pedir?fecha=' + C.sel }, 'Pedir este día')));
      }
      det.append(caja);
      if (scroll) caja.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  function initCal() {
    if (C.listo) return;
    C.listo = true;
    C.mes = AUS.hoyISO().slice(0, 7);
    poblarVista();
    $('c-ant').onclick = () => { C.mes = AUS.mesAdd(C.mes, -1); C.sel = null; cargarMes(); };
    $('c-sig').onclick = () => { C.mes = AUS.mesAdd(C.mes, 1); C.sel = null; cargarMes(); };
    $('c-hoy').onclick = () => { C.mes = AUS.hoyISO().slice(0, 7); C.sel = AUS.hoyISO(); cargarMes(); };
    $('c-vista').onchange = () => { aplicarVista(); pintarCal(false); };
    document.querySelectorAll('input[name="c-modo"]').forEach((r) => r.addEventListener('change', () => { aplicarVista(); pintarCal(false); }));
    $('c-imprimir').onclick = () => window.print();
  }
  function abrirCal() { initCal(); cargarMes(); }

  // ═══════════ Formulario ═══════════
  // turnos: mis turnos libres; reemp: {turno_id: {id, nombre}}; todos/uno: reemplazo general o del único turno; con: compañero del cambio
  const F = { tipo: null, fraccion: 'completo', llenos: [], sinSaldo: '', seq: 0, turnos: [], reemp: {}, todos: null, uno: null, con: null };

  const jornada = () => (SES ? SES.persona.jornada : null);   // siempre la persona de la sesión
  const unidades = () => CFG.equipos.filter((e) => e.tiene_turnantes);
  // Tipo elegido, con su configuración (SPEC-v2 §3.5): rango, medio_dia, es_cambio_turno, exige_reemplazo…
  const T = () => (F.tipo && CFG.tipos.find((x) => x.sigla === F.tipo)) || {};

  function opcionesSelect(sel, items, vacio) {
    vaciar(sel);
    if (vacio) sel.append(h('option', { value: '' }, vacio));
    items.forEach((e) => sel.append(h('option', { value: e.id }, e.nombre)));
  }

  function resetForm() {
    Object.assign(F, { tipo: null, fraccion: 'completo', llenos: [], reemp: {}, todos: null, uno: null, con: null });
    ['f-desde', 'f-hasta', 'f-comentario', 'ft-reemp', 'ft-todos', 'fc-con', 'fs-just'].forEach((i) => { $(i).value = ''; });
    $('fs-acepta').checked = false;
    vaciar($('fc-dev')).append(h('option', { value: '' }, 'Sin devolución'));
    $('f-error').textContent = '';
    $('f-desde').min = AUS.hoyISO();
    $('f-hasta').min = AUS.hoyISO();
    const p = SES.persona;
    $('fi-nombre').textContent = p.nombre;
    $('fi-jornada').textContent = p.jornada === 'turnante' ? 'Turnante' : 'Diurno';
    $('fi-equipo').textContent = p.equipo || (p.jornada === 'turnante' ? 'Rota entre unidades' : '—');
    renderTipos();
    actualizar();
  }

  function renderTipos() {
    const j = jornada();
    const box = vaciar($('f-tipos'));
    mostrarEl($('f-tipo-box'), !!j);
    if (!j) { F.tipo = null; return; }
    const tipos = CFG.tipos.filter((x) => x.lo_pide === 'funcionario' && (j === 'turnante' || !x.es_cambio_turno)).map((x) => x.sigla);
    if (!tipos.includes(F.tipo)) F.tipo = null;
    tipos.forEach((t) => box.append(h('label', { class: 'opcion' },
      h('input', { type: 'radio', name: 'tipo', value: t, checked: F.tipo === t, onchange: () => { F.tipo = t; renderFracs(); actualizar(); } }),
      h('span', null, CFG.tipos.find((x) => x.sigla === t).nombre))));
    renderFracs();
  }

  function renderFracs() {
    const j = jornada();
    const opts = j === 'turnante'
      ? [['completo', 'Turno completo'], ['primera_mitad', 'Primera mitad'], ['segunda_mitad', 'Segunda mitad']]
      : [['completo', 'Día completo'], ['am', 'Mañana (AM)'], ['pm', 'Tarde (PM)']];
    if (!opts.some((o) => o[0] === F.fraccion)) F.fraccion = 'completo';
    const box = vaciar($('f-fracs'));
    opts.forEach(([v, t]) => box.append(h('label', { class: 'opcion' },
      h('input', { type: 'radio', name: 'fraccion', value: v, checked: F.fraccion === v, onchange: () => { F.fraccion = v; actualizar(); } }), h('span', null, t))));
  }

  function actualizar() {
    const j = jornada();
    const t = F.tipo;
    const tp = T();
    const unTurno = j === 'turnante' && (tp.rango === 'un_dia' || !!tp.es_cambio_turno);   // el turnante elige un turno, no fechas
    mostrarEl($('f-fechas'), !!(j && t) && !unTurno);
    mostrarEl($('w-hasta'), tp.rango === 'rango');
    mostrarEl($('f-frac-box'), !!tp.medio_dia);
    mostrarEl($('w-comentario'), !!(j && t));
    mostrarEl($('f-turn'), j === 'turnante' && !!t);
    mostrarEl($('f-ct'), j === 'turnante' && !!tp.es_cambio_turno);
    $('l-desde').textContent = tp.rango === 'rango' ? 'Desde' : 'Fecha';
    if (j === 'turnante' && t) pintarTurnante(unTurno);
    simular();
  }

  // ----- Turnantes: sobre sus turnos cargados (SPEC-v2 §4.3) -----
  const etiquetaTurno = (x) => AUS.fmtLargo(x.fecha) + ' · ' + cap(x.tipo) + ' · ' + x.unidad;
  const turnosEnRango = () => {
    const d = $('f-desde').value, hst = $('f-hasta').value || d;
    return d ? F.turnos.filter((x) => x.fecha >= d && x.fecha <= hst) : [];
  };

  async function cargarMisTurnos() {
    try { F.turnos = (await api('turnos/mios')).turnos.filter((x) => x.estado === 'normal' && !x.en_solicitud); }
    catch (e) { F.turnos = []; $('f-error').textContent = e.message; }
  }

  /** Campo de texto con autocompletado de la nómina que guarda {id, nombre} en guardar(). */
  function selectorPersona(input, guardar) {
    if (input.dataset.ac) return;
    input.dataset.ac = '1';
    AUS.autocompletar(input, { onPick: (p) => { guardar({ id: p.id, nombre: p.nombre }); simular(); }, onClear: () => guardar(null) });
  }

  function pintarTurnante(unTurno) {
    const tp = T();
    mostrarEl($('ft-w-turno'), unTurno);
    mostrarEl($('ft-w-reemp'), unTurno && !tp.es_cambio_turno);
    mostrarEl($('ft-w-todos'), !unTurno);
    const lista = vaciar($('ft-lista'));
    const vacio = $('ft-vacio');
    mostrarEl(vacio, false);
    if (unTurno) {
      const sel = $('ft-turno'), previo = sel.value;
      vaciar(sel).append(h('option', { value: '' }, F.turnos.length ? 'Elige tu turno…' : 'No tienes turnos cargados'), ...F.turnos.map((x) => h('option', { value: x.id }, etiquetaTurno(x))));
      sel.value = previo;
      if (!F.turnos.length) { vacio.textContent = 'No tienes turnos cargados desde hoy. Si falta alguno, avisa a coordinación.'; mostrarEl(vacio, true); }
      return;
    }
    const tr = turnosEnRango();
    if ($('f-desde').value && !tr.length) { vacio.textContent = 'No tienes turnos cargados en esas fechas. Si falta alguno, avisa a coordinación.'; mostrarEl(vacio, true); }
    tr.forEach((x) => {
      const inp = h('input', { class: 'input', type: 'text', placeholder: F.todos ? F.todos.nombre : 'Quién lo cubre', 'aria-label': 'Quién cubre el turno del ' + AUS.fmt(x.fecha),
        value: F.reemp[x.id] ? F.reemp[x.id].nombre : '' });
      const caja = h('div', null, inp);
      lista.append(h('div', { class: 'dia-turno' }, h('span', { class: 'fecha' }, etiquetaTurno(x)), caja));
      selectorPersona(inp, (p) => { if (p) F.reemp[x.id] = p; else delete F.reemp[x.id]; });
    });
  }

  async function cargarDevoluciones() {
    const sel = vaciar($('fc-dev'));
    sel.append(h('option', { value: '' }, 'Sin devolución'));
    if (!F.con) return;
    try { (await api('turnos/de/' + F.con.id)).turnos.filter((x) => x.estado === 'normal').forEach((x) => sel.append(h('option', { value: x.id }, etiquetaTurno(x)))); }
    catch (e) { /* el compañero no es turnante: sin devolución posible */ }
  }
  // ----- Resumen en vivo (simulación en el servidor) -----
  function textoFechas(dias) {
    const f = dias.map((d) => AUS.fmt(d.fecha).slice(0, 5));
    return f.length <= 6 ? f.join(', ') : f[0] + ' al ' + f[f.length - 1] + ' (' + f.length + ' días)';
  }

  function ocultarSobre() { mostrarEl($('f-sobre'), false); }
  function mostrarSobre() {
    mostrarEl($('f-sobre'), true);
    mostrarEl($('fs-wj'), $('fs-acepta').checked);
  }

  const simular = AUS.debounce(async () => {
    const box = vaciar($('f-resumen'));
    F.llenos = [];
    F.sinSaldo = '';
    ocultarSobre();
    const j = jornada();
    if (!j || !F.tipo) return;
    const unTurno = j === 'turnante' && (T().rango === 'un_dia' || T().es_cambio_turno);
    if (unTurno ? !$('ft-turno').value : !$('f-desde').value) return;
    const mi = ++F.seq;
    try {
      const s = await api('solicitudes/simular', { cuerpo: cuerpo() });
      if (mi !== F.seq) return;
      if (j === 'turnante') box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(s.dias.length, 'turno', 'turnos') + ': '), textoFechas(s.dias), '. Los turnantes no usan cupo; coordinación autoriza igualmente.'));
      else box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(s.habiles, 'día hábil', 'días hábiles') + ': '), textoFechas(s.dias)));
      (s.saldo || []).forEach((x) => {
        const nombre = x.saldo === 'FL' ? 'feriado legal' : 'administrativos';
        if (x.insuficiente) {
          F.sinSaldo = 'No te alcanza el saldo de ' + nombre + ' ' + x.anio + ': te quedan ' + AUS.num(x.disponible) + ' y esta solicitud usa ' + AUS.num(x.usa) + '. Habla con coordinación.';
          box.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, 'Saldo insuficiente. '), F.sinSaldo.replace(/^No te alcanza /, 'No alcanza ')));
        } else {
          box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, 'Saldo ' + x.saldo + ' ' + x.anio + ': '), 'usa ' + AUS.num(x.usa) + ', te quedarán ' + AUS.num(x.disponible - x.usa) + ' de ' + AUS.num(x.disponible) + '.'));
        }
      });
      if (s.fl) {
        // Bloque de vacaciones o FL fraccionado (máximo de días fraccionados por año).
        if (s.fl.modalidad === 'bloque') {
          box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, 'Bloque de vacaciones: '), 'descuenta solo de lunes a viernes, sin festivos.'));
        } else {
          (s.fl.fraccionado || []).forEach((x) => {
            const queda = x.maximo - x.usado;
            if (x.excede) {
              F.sinSaldo = 'El feriado legal fraccionado es de máximo ' + AUS.num(x.maximo) + ' días al año. En ' + x.anio + ' te quedan ' + AUS.num(Math.max(queda, 0)) +
                ' y esta solicitud usa ' + AUS.num(x.usa) + '. El resto se toma en un bloque de ' + s.fl.bloque_min + ' días hábiles o más.';
              box.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, 'Supera los días fraccionados. '), F.sinSaldo));
            } else {
              box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, 'FL fraccionado ' + x.anio + ': '),
                'usa ' + AUS.num(x.usa) + (j === 'turnante' ? ' (1 por turno)' : '') + '; te quedarán ' + AUS.num(queda - x.usa) + ' de ' + AUS.num(x.maximo) +
                ' días fraccionados. Un bloque de ' + s.fl.bloque_min + ' días hábiles o más no los gasta.'));
            }
          });
        }
      }
      if (s.omitidos.length) box.append(h('p', { class: 'nota' }, 'Se omiten ' + AUS.plural(s.omitidos.length, 'día no hábil', 'días no hábiles') + ' (fin de semana o feriado): ' + s.omitidos.map((o) => AUS.fmt(o.fecha).slice(0, 5)).join(', ') + '.'));
      if (j === 'turnante') return;
      if (s.consumo_total === 0) box.append(h('p', { class: 'nota' }, T().consume_cupo ? 'Feriado legal largo (5 o más días hábiles): no usa cupo, pero aparece en el calendario.' : 'Este tipo no usa cupo del equipo, pero aparece en el calendario.'));
      else box.append(h('p', { class: 'nota' }, 'Uso de cupo: ' + AUS.num(s.consumo_total) + ' día(s). Cupo del equipo: ' + AUS.num(s.cupo) + ' en la mañana y ' + AUS.num(s.cupo) + ' en la tarde.'));
      if (s.dias_llenos.length) {
        F.llenos = s.dias_llenos;
        box.append(h('div', { class: 'aviso aviso-precaucion' }, h('b', null, 'Sin cupo: '),
          s.dias_llenos.map((d) => AUS.fmt(d.fecha).slice(0, 5) + ' (' + AUS.bloquesTxt(d.bloques) + ': ' + AUS.num(d.ocupado) + ' de ' + AUS.num(d.cupo) + ')').join(', ') +
          '. Puedes cambiar las fechas o enviarla igualmente como solicitud sobre cupo con una justificación.'));
        mostrarSobre();
      } else box.append(h('div', { class: 'aviso aviso-normal' }, h('b', null, 'Hay cupo disponible'), ' en esas fechas (queda pendiente de autorización).'));
    } catch (e) {
      if (mi === F.seq) box.append(h('p', { class: 'msg-error' }, e.message));
    }
  }, 300);

  function cuerpo() {
    const j = jornada();
    const b = { tipo: F.tipo, comentario: $('f-comentario').value.trim() };   // la persona la pone el servidor desde la sesión
    if (j === 'diurno') {
      b.fecha_inicio = $('f-desde').value;
      b.fecha_fin = T().rango === 'rango' ? ($('f-hasta').value || b.fecha_inicio) : b.fecha_inicio;
      b.fraccion = T().medio_dia ? F.fraccion : 'completo';
    } else {
      const tp = T();
      if (tp.es_cambio_turno) Object.assign(b, { turno_id: +$('ft-turno').value || null, cambio_con_id: F.con ? F.con.id : null, devolucion_turno_id: +$('fc-dev').value || null });
      else if (tp.rango === 'un_dia') Object.assign(b, { turno_id: +$('ft-turno').value || null, reemplazo_id: F.uno ? F.uno.id : null, fraccion: tp.medio_dia ? F.fraccion : 'completo' });
      else {
        b.fecha_inicio = $('f-desde').value;
        b.fecha_fin = $('f-hasta').value || b.fecha_inicio;
        b.reemplazos = Object.fromEntries(turnosEnRango().map((x) => [x.id, (F.reemp[x.id] || F.todos || {}).id || null]));
      }
    }
    b.acepta_sobre_cupo = $('fs-acepta').checked;
    b.justificacion = $('fs-just').value.trim();
    return b;
  }

  async function enviar(ev) {
    ev.preventDefault();
    const err = $('f-error');
    const fallo = (m) => { err.textContent = m; err.scrollIntoView({ block: 'center', behavior: 'smooth' }); };
    err.textContent = '';
    if (!F.tipo) return fallo('Elige qué necesitas.');
    const unTurno = jornada() === 'turnante' && (T().rango === 'un_dia' || T().es_cambio_turno);
    if (unTurno && !$('ft-turno').value) return fallo('Elige tu turno.');
    if (!unTurno && !$('f-desde').value) return fallo('Indica la fecha.');
    if (F.sinSaldo) return fallo(F.sinSaldo);
    if (F.llenos.length && !$('fs-acepta').checked) return fallo('Hay días sin cupo. Cambia las fechas o marca «Enviar igualmente como solicitud sobre cupo».');
    if ($('fs-acepta').checked && F.llenos.length && !$('fs-just').value.trim()) return fallo('Escribe la justificación para enviar sobre cupo.');
    const btn = $('f-enviar');
    btn.disabled = true;
    try {
      const r = await api('solicitudes', { cuerpo: cuerpo() });
      ULTIMA = r;
      location.hash = '#/ok';
    } catch (e) {
      if (e.data && e.data.codigo === 'sin_cupo') {
        F.llenos = e.data.dias_llenos;
        mostrarSobre();
        fallo('Mientras llenabas el formulario se ocupó el cupo de: ' + F.llenos.map((d) => AUS.fmt(d.fecha)).join(', ') + '. Cambia las fechas o envíala como solicitud sobre cupo.');
      } else fallo(e.message);
    } finally { btn.disabled = false; }
  }

  function initForm() {
    if (initForm.hecho) return;
    initForm.hecho = true;
    ['f-desde', 'f-hasta'].forEach((i) => $(i).addEventListener('change', () => {
      if (i === 'f-desde' && $('f-hasta').value && $('f-hasta').value < $('f-desde').value) $('f-hasta').value = '';
      if (i === 'f-desde') $('f-hasta').min = $('f-desde').value || AUS.hoyISO();
      actualizar();
    }));
    $('ft-turno').onchange = () => simular();
    $('fc-dev').onchange = () => simular();
    selectorPersona($('ft-reemp'), (p) => { F.uno = p; });
    selectorPersona($('ft-todos'), (p) => { F.todos = p; pintarTurnante(false); });
    selectorPersona($('fc-con'), (p) => { F.con = p; cargarDevoluciones(); });
    $('fs-acepta').onchange = () => mostrarEl($('fs-wj'), $('fs-acepta').checked);
    $('form').addEventListener('submit', enviar);
  }

  async function abrirForm(q) {
    initForm();
    if (SES.persona.jornada === 'turnante') await cargarMisTurnos();
    resetForm();
    const fecha = q.get('fecha');
    if (fecha && /^\d{4}-\d{2}-\d{2}$/.test(fecha) && fecha >= AUS.hoyISO()) { $('f-desde').value = fecha; actualizar(); }
  }

  // ═══════════ Confirmación ═══════════
  function abrirOk() {
    const r = ULTIMA;
    const caja = vaciar($('ok-caja'));
    caja.append(
      h('p', { class: 'etiqueta' }, 'Tu código de solicitud'),
      h('p', { class: 'cod-grande' }, r.codigo),
      h('p', null, 'Estado: ', AUS.estadoChip('pendiente'), ' ', r.sobre_cupo ? AUS.chip('Sobre cupo', 'chip-precaucion') : ''),
      h('p', null, r.habiles === 1 ? 'Se registró 1 día.' : 'Se registraron ' + r.habiles + ' días.'),
      h('p', { class: 'nota' }, r.omitidos && r.omitidos.length ? 'No se contaron: ' + r.omitidos.map((o) => AUS.fmt(o.fecha).slice(0, 5)).join(', ') + ' (fin de semana o feriado).' : ''),
      h('p', { class: 'nota' }, 'Guarda este código. Coordinación la revisará; puedes ver si fue autorizada o no en «Mis solicitudes».'),
    );
  }

  // ═══════════ Mis solicitudes (solo las propias: el servidor filtra por la sesión) ═══════════
  async function abrirMis() {
    $('m-error').textContent = '';
    const cont = vaciar($('m-lista'));
    try {
      const r = await api('solicitudes');
      if (!r.solicitudes.length) { cont.append(h('p', { class: 'aviso aviso-info' }, 'Aún no tienes solicitudes. Usa «Pedir días» para crear una.')); return; }
      r.solicitudes.forEach((s) => cont.append(tarjetaSol(s, abrirMis)));
    } catch (e) { $('m-error').textContent = e.message; }
  }

  function tarjetaSol(s, recargar) {
    const fechas = s.fecha_inicio === s.fecha_fin ? AUS.fmt(s.fecha_inicio) : AUS.fmt(s.fecha_inicio) + ' al ' + AUS.fmt(s.fecha_fin);
    const card = h('article', { class: 'sol' },
      h('div', { class: 'sol-cab' }, h('span', { class: 'cod' }, s.codigo), AUS.chip(AUS.tipoTxt(s.tipo, s.fraccion, s.etiqueta), 'chip-neutro'), AUS.estadoChip(s.estado), s.sobre_cupo ? AUS.chip('Sobre cupo', 'chip-precaucion') : null),
      h('div', null, h('strong', null, s.nombre), ' · ' + fechas + (s.turno ? ' · turno ' + s.turno : '')));
    if (s.estado === 'rechazada') card.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, 'Motivo del rechazo: '), s.motivo || 'No se indicó motivo.'));
    if (s.estado === 'autorizada') card.append(h('div', { class: 'aviso aviso-normal' }, h('b', null, 'Autorizada.')));
    if (s.estado === 'pendiente') card.append(h('div', { class: 'aviso aviso-info' }, 'Esperando la revisión de coordinación.'));
    if (s.anulable) {
      const zona = h('div');
      const abrir = h('button', { type: 'button', class: 'btn btn-peligro btn-chico', onclick: () => {
        abrir.classList.add('oculto');
        const msg = h('p', { class: 'msg-error', role: 'alert' });
        const ok = h('button', { type: 'button', class: 'btn btn-peligro btn-chico', onclick: async () => {
          msg.textContent = ''; ok.disabled = true;
          try { await api('solicitudes/' + s.id + '/anular', { cuerpo: {} }); AUS.toast('Solicitud anulada'); recargar(); }
          catch (e) { msg.textContent = e.message; ok.disabled = false; }
        } }, 'Sí, anular ' + s.codigo);
        zona.append(h('div', { class: 'fila' }, h('span', null, '¿Anular esta solicitud?'), ok,
          h('button', { type: 'button', class: 'btn btn-chico', onclick: () => { vaciar(zona); zona.append(abrir); abrir.classList.remove('oculto'); } }, 'No')), msg);
        ok.focus();
      } }, 'Anular');
      zona.append(abrir);
      card.append(zona, h('p', { class: 'nota' }, 'Se puede anular hasta las 23:59 del día anterior a la primera fecha.'));
    }
    return card;
  }

  // ═══════════ Mis turnos ═══════════
  const ESTADO_TURNO = { normal: null, cubierto: ['Te cubren', 'chip-normal'], sin_cubrir: ['Ausente', 'chip-info'], cambio: ['Por cambio', 'chip-info'] };
  async function abrirTurnos() {
    $('t-error').textContent = '';
    const mios = vaciar($('t-mios')), cubro = vaciar($('t-cubro'));
    try {
      const r = await api('turnos/mios');
      mios.append(h('h2', null, 'Mis próximos turnos'));
      if (!r.turnos.length) mios.append(h('p', { class: 'nota' }, SES.persona.jornada === 'turnante' ? 'No tienes turnos cargados desde hoy. Si falta alguno, avisa a coordinación.' : 'Los diurnos no tienen turnos propios; aquí verás los turnos que cubras.'));
      else mios.append(h('ul', { class: 'lista' }, r.turnos.map((x) => {
        const e = ESTADO_TURNO[x.estado];
        return h('li', null, h('strong', null, AUS.fmtLargo(x.fecha)), ' · ' + cap(x.tipo) + ' · ' + x.unidad, e ? AUS.chip(e[0], e[1]) : null,
          !e && x.en_solicitud ? AUS.chip('En ' + x.en_solicitud, 'chip-info') : null,
          x.cubierto_por ? h('span', { class: 'nota' }, 'Cubre: ' + x.cubierto_por) : null);
      })));
      cubro.append(h('h2', null, 'Turnos que cubro'));
      if (!r.cubro.length) cubro.append(h('p', { class: 'nota' }, 'No tienes turnos por cubrir.'));
      else cubro.append(h('ul', { class: 'lista' }, r.cubro.map((x) => h('li', null, h('strong', null, AUS.fmtLargo(x.fecha)), ' · ' + cap(x.tipo) + ' · ' + x.unidad,
        h('span', { class: 'nota' }, 'en lugar de ' + x.titular), x.cubierto_extra ? AUS.chip('Extra', 'chip-precaucion') : null))));
    } catch (e) { $('t-error').textContent = e.message; }
  }

  // ═══════════ Mi perfil ═══════════
  function fechaHora(s) { return AUS.fmt(s.slice(0, 10)) + ' ' + s.slice(11, 16); }
  function dispositivo(ua) {
    ua = ua || '';
    const so = /Android/i.test(ua) ? 'Android' : /iPhone|iPad/i.test(ua) ? 'iPhone/iPad' : /Windows/i.test(ua) ? 'Windows' : /Mac/i.test(ua) ? 'Mac' : 'Otro equipo';
    const nav = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'navegador';
    return so + ' · ' + nav;
  }

  async function abrirPerfil() {
    const p = SES.persona;
    vaciar($('p-ficha')).append(
      h('div', null, h('dt', null, 'Nombre'), h('dd', null, p.nombre)),
      h('div', null, h('dt', null, 'Jornada'), h('dd', null, cap(p.jornada))),
      h('div', null, h('dt', null, 'Equipo'), h('dd', null, p.equipo || '—')),
      h('div', null, h('dt', null, 'Calidad'), h('dd', null, cap(p.calidad))),
      h('div', null, h('dt', null, 'Rol'), h('dd', null, p.rol === 'coordinacion' ? 'Coordinación' : 'Funcionario')));
    const caja = vaciar($('p-saldos'));
    try {
      const r = await api('saldos/mios');
      caja.append(h('h2', null, 'Mis saldos ' + r.anio));
      const fila = h('div', { class: 'dos-col' });
      [['FL', 'Feriado legal'], ['ADM', 'Administrativos']].forEach(([k, n]) => {
        const x = r.saldos[k];
        // MetricCard de Vital: número grande = lo que queda; el pie explica de dónde sale.
        fila.append(h('section', { class: 'vt-metric', 'aria-label': n + ': ' + AUS.num(x.disponible) + ' días disponibles' },
          h('div', { class: 'vt-metric__head' }, h('span', { class: 'vt-metric__label' }, n + ' (' + k + ')')),
          h('div', { class: 'vt-metric__value' }, h('span', { class: 'vt-metric__num' }, AUS.num(x.disponible)), h('span', { class: 'vt-metric__unit' }, 'días disponibles')),
          h('div', { class: 'vt-metric__foot' }, h('span', { class: 'meta' }, 'De ' + AUS.num(x.asignado + x.ajustes) + (x.ajustes ? ' (incluye ajuste ' + (x.ajustes > 0 ? '+' : '') + AUS.num(x.ajustes) + ')' : '') +
            ' · usados ' + AUS.num(x.autorizado) + ' · pendientes ' + AUS.num(x.pendiente)))));
      });
      caja.append(fila);
      if (r.fl_fraccionado) {
        const fr = r.fl_fraccionado;
        caja.append(h('p', { class: 'nota' }, 'FL fraccionado: llevas ' + AUS.num(fr.usado) + ' de ' + AUS.num(fr.maximo) +
          ' días este año. El resto del feriado legal se toma en un bloque de ' + fr.bloque_min + ' días hábiles o más.'));
      }
      caja.append(h('p', { class: 'nota' }, 'Saldo interno del equipo: si no coincide con RR.HH., avisa a coordinación. Las solicitudes pendientes ya se descuentan.'));
      r.ajustes.forEach((a) => caja.append(h('p', { class: 'meta' }, 'Ajuste ' + a.saldo + ' ' + (a.dias > 0 ? '+' : '') + AUS.num(a.dias) + ': ' + a.motivo)));
    } catch (e) { caja.append(h('p', { class: 'msg-error' }, e.message)); }
    const ul = vaciar($('p-sesiones'));
    try {
      const r = await api('auth/sesiones');
      r.sesiones.forEach((x) => ul.append(h('li', null,
        h('span', null, h('strong', null, dispositivo(x.user_agent)), x.compartido ? ' · computador compartido' : '', h('br'),
          h('span', { class: 'meta' }, 'Último uso: ' + fechaHora(x.ultimo_uso))),
        h('span', { class: 'espacio' }),
        x.actual ? AUS.chip('Este dispositivo', 'chip-info')
          : h('button', { type: 'button', class: 'btn btn-chico', onclick: async () => { await api('auth/sesiones/' + x.id + '/cerrar', { cuerpo: {} }); AUS.toast('Sesión cerrada'); abrirPerfil(); } }, 'Cerrar'))));
    } catch (e) { ul.append(h('li', { class: 'msg-error' }, e.message)); }
  }

  function initPerfil() {
    if (initPerfil.hecho) return;
    initPerfil.hecho = true;
    $('p-pin').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      $('p-error').textContent = '';
      try {
        await api('auth/pin', { cuerpo: { actual: $('p-actual').value, nuevo: $('p-nuevo').value } });
        $('p-actual').value = ''; $('p-nuevo').value = '';
        AUS.toast('PIN cambiado');
      } catch (e) { $('p-error').textContent = e.message; }
    });
    $('p-otras').onclick = async () => { const r = await api('auth/sesiones/cerrar-otras', { cuerpo: {} }); AUS.toast(AUS.plural(r.cerradas, 'sesión cerrada', 'sesiones cerradas')); abrirPerfil(); };
    $('p-salir').onclick = async () => { try { await api('auth/salir', { cuerpo: {} }); } catch (e) { /* ya no había sesión */ } salir(''); };
  }

  // ═══════════ Ingreso / activación ═══════════
  const modoIngreso = () => document.querySelector('input[name="i-modo"]:checked').value;

  function pintarModo() {
    const act = modoIngreso() === 'activar';
    mostrarEl($('i-w-codigo'), act); mostrarEl($('i-w-pin2'), act); mostrarEl($('i-ayuda-activar'), act);
    $('i-l-pin').textContent = act ? 'Crea tu PIN (6 números)' : 'PIN (6 números)';
    $('i-pin').autocomplete = act ? 'new-password' : 'current-password';
    $('i-enviar').textContent = act ? 'Activar y entrar' : 'Ingresar';
    $('i-error').textContent = '';
  }

  function mostrarIngreso(msg) {
    SES = null; AUS.csrf = null;
    mostrarEl($('barra'), false); mostrarEl($('nav'), false);
    document.body.classList.remove('con-barra-abajo');
    document.querySelectorAll('main > section').forEach((s) => mostrarEl(s, false));
    mostrarEl($('v-ingreso'), true);
    $('i-error').textContent = msg || '';
    $('i-pin').value = ''; $('i-pin2').value = '';
    if (!initIngreso.hecho) initIngreso();
    setTimeout(() => ($('i-rut').value ? $('i-pin') : $('i-rut')).focus(), 50);
  }

  function initIngreso() {
    initIngreso.hecho = true;
    document.querySelectorAll('input[name="i-modo"]').forEach((r) => r.addEventListener('change', pintarModo));
    if (/activar/.test(location.hash)) { document.querySelector('input[name="i-modo"][value="activar"]').checked = true; }
    pintarModo();
    $('i-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const err = $('i-error');
      err.textContent = '';
      const act = modoIngreso() === 'activar';
      const pin = $('i-pin').value.trim();
      if (!/^\d{6}$/.test(pin)) { err.textContent = 'El PIN debe tener exactamente 6 números.'; return; }
      if (act && pin !== $('i-pin2').value.trim()) { err.textContent = 'Los dos PIN no coinciden.'; return; }
      const cuerpo = { rut: $('i-rut').value.trim(), pin, compartido: $('i-compartido').checked };
      if (act) cuerpo.codigo = $('i-codigo').value.trim();
      $('i-enviar').disabled = true;
      try {
        const r = await api(act ? 'auth/activar' : 'auth/ingresar', { cuerpo });
        await entrar(r);
      } catch (e) { err.textContent = e.message; }
      finally { $('i-enviar').disabled = false; }
    });
  }

  function salir(msg) { mostrarIngreso(msg); history.replaceState(null, '', location.pathname + location.search); }

  // ═══════════ Ruteo ═══════════
  function mostrar() {
    if (!CFG || !SES) return;
    const [path, qs] = (location.hash || '#/').slice(1).split('?');
    const q = new URLSearchParams(qs || '');
    document.querySelectorAll('main > section').forEach((s) => mostrarEl(s, false));
    let vista = 'cal';
    if (path === '/pedir') vista = 'pedir';
    else if (path === '/mis') vista = 'mis';
    else if (path === '/perfil') vista = 'perfil';
    else if (path === '/turnos') vista = 'turnos';
    else if (path === '/ok' && ULTIMA) vista = 'ok';
    mostrarEl($('v-' + vista), true);
    ['cal', 'mis', 'turnos', 'perfil'].forEach((v) => $('nav-' + v).toggleAttribute('aria-current', false));
    if (['cal', 'mis', 'turnos', 'perfil'].includes(vista)) $('nav-' + vista).setAttribute('aria-current', 'page');
    if (vista === 'cal') abrirCal();
    else if (vista === 'pedir') abrirForm(q);
    else if (vista === 'mis') abrirMis();
    else if (vista === 'perfil') { initPerfil(); abrirPerfil(); }
    else if (vista === 'turnos') abrirTurnos();
    else abrirOk();
    window.scrollTo(0, 0);
  }

  async function entrar(sesion) {
    SES = { persona: sesion.persona, compartido: sesion.compartido };
    AUS.csrf = sesion.csrf;
    if (!CFG) {
      CFG = await api('config');
      AUS.hoy = CFG.hoy;
      $('titulo').textContent = CFG.titulo;
      document.title = CFG.titulo;
      if (CFG.logo) { const l = $('logo'); l.src = CFG.logo; mostrarEl(l, true); }
    }
    mostrarEl($('v-ingreso'), false);
    mostrarEl($('barra'), true); mostrarEl($('nav'), true);
    document.body.classList.add('con-barra-abajo');
    mostrarEl($('nav-coord'), SES.persona.rol === 'coordinacion');
    mostrar();
  }

  async function iniciar() {
    window.addEventListener('hashchange', mostrar);
    // Si una petición descubre que la sesión terminó (venció o la cerraron), se vuelve a la pantalla de ingreso.
    window.addEventListener('aus:sin-sesion', () => salir('Tu sesión terminó. Ingresa de nuevo con tu RUT y PIN.'));
    try {
      const s = await api('auth/sesion');
      if (s.autenticado) await entrar(s); else mostrarIngreso('');
    } catch (e) {
      $('main').prepend(h('p', { class: 'aviso aviso-critico', role: 'alert' }, e.message));
    }
  }
  iniciar();
})();
