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

  function poblarVista() {
    const sel = $('c-vista');
    vaciar(sel);
    const g = h('optgroup', { label: 'Equipos (diurnos)' });
    CFG.equipos.filter((e) => e.tiene_diurnos).forEach((e) => g.append(h('option', { value: 'e:' + e.id }, e.nombre)));
    sel.append(g, h('option', { value: 'todos' }, 'Todos los equipos'), h('option', { value: 'turn' }, 'Turnantes'));
    let v = AUS.leer('aus_vista', null);
    if (!v || ![...sel.options].some((o) => o.value === v)) v = sel.options[0].value;
    sel.value = v;
    C.vista = parseVista(v);
  }
  function parseVista(v) {
    if (v === 'todos') return { tipo: 'todos' };
    if (v === 'turn') return { tipo: 'turn' };
    return { tipo: 'equipo', equipoId: +v.slice(2) };
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
    $('c-vista').onchange = (ev) => { AUS.guardar('aus_vista', ev.target.value); C.vista = parseVista(ev.target.value); pintarCal(false); };
    $('c-imprimir').onclick = () => window.print();
  }
  function abrirCal() { initCal(); cargarMes(); }

  // ═══════════ Formulario ═══════════
  const F = { tipo: null, fraccion: 'completo', dias: [], llenos: [], sinSaldo: '', seq: 0 };

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
    Object.assign(F, { tipo: null, fraccion: 'completo', dias: [], llenos: [] });
    ['f-desde', 'f-hasta', 'f-comentario', 'fd-reemplazo', 'fc-con', 'fc-fecha', 'fs-just'].forEach((i) => { $(i).value = ''; });
    $('fs-acepta').checked = false;
    $('fc-turno').value = '';
    $('f-error').textContent = '';
    opcionesSelect($('fd-unidad'), unidades(), 'Elige la unidad…');
    $('f-desde').min = AUS.hoyISO();
    $('f-hasta').min = AUS.hoyISO();
    $('fc-fecha').min = AUS.hoyISO();
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
    mostrarEl($('f-fechas'), !!(j && t));
    mostrarEl($('w-hasta'), tp.rango === 'rango');
    mostrarEl($('f-frac-box'), !!tp.medio_dia);
    mostrarEl($('w-comentario'), !!(j && t));
    mostrarEl($('f-turn'), j === 'turnante' && !!t); // FL, ADM y CT necesitan turno y unidad por fecha
    mostrarEl($('f-defaults'), j === 'turnante' && tp.rango === 'rango' && !tp.es_cambio_turno);
    mostrarEl($('f-ct'), j === 'turnante' && !!tp.es_cambio_turno);
    $('l-desde').textContent = tp.rango === 'rango' ? 'Desde' : tp.es_cambio_turno ? 'Fecha del turno que cedes' : 'Fecha';
    if (j === 'turnante' && t) sincronizarDias(); else { F.dias = []; renderDias(); }
    simular();
  }

  // ----- Turnantes: una fila por día -----
  function sincronizarDias() {
    const t = F.tipo;
    const d = $('f-desde').value;
    let fechas = [];
    if (d) {
      if (T().rango === 'rango' && !T().es_cambio_turno) {
        const hasta = $('f-hasta').value || d;
        let f = d;
        while (f <= hasta && fechas.length < 62) { fechas.push(f); f = AUS.addDias(f, 1); }
      } else fechas = [d];
    }
    const previos = Object.fromEntries(F.dias.map((x) => [x.fecha, x]));
    F.dias = fechas.map((f) => previos[f] || { fecha: f, turno: $('fd-turno').value, unidad_id: +$('fd-unidad').value || 0, reemplazo: $('fd-reemplazo').value });
    renderDias();
  }

  function renderDias() {
    const box = vaciar($('f-dias'));
    F.dias.forEach((d) => {
      const sTurno = h('select', { class: 'input', 'aria-label': 'Turno del ' + AUS.fmt(d.fecha), onchange: (e) => { d.turno = e.target.value; } },
        h('option', { value: 'largo' }, 'Turno largo'), h('option', { value: 'noche' }, 'Turno noche'));
      sTurno.value = d.turno;
      const sUni = h('select', { class: 'input', 'aria-label': 'Unidad del ' + AUS.fmt(d.fecha), onchange: (e) => { d.unidad_id = +e.target.value || 0; } },
        h('option', { value: '' }, 'Unidad…'), unidades().map((u) => h('option', { value: u.id }, u.nombre)));
      sUni.value = d.unidad_id || '';
      const fila = h('div', { class: 'dia-turno' }, h('span', { class: 'fecha' }, AUS.fmtLargo(d.fecha)), sTurno, sUni);
      if (!T().es_cambio_turno) {
        const r = h('input', { class: 'input reemp', type: 'text', maxlength: '120', placeholder: 'Quién te cubre', 'aria-label': 'Quién cubre el ' + AUS.fmt(d.fecha), value: d.reemplazo || '',
          oninput: (e) => { d.reemplazo = e.target.value; } });
        AUS.datalistNomina(r);
        fila.append(r);
      }
      if (T().rango === 'rango' && F.dias.length > 1) {
        fila.append(h('button', { type: 'button', class: 'btn btn-chico', 'aria-label': 'Quitar el ' + AUS.fmt(d.fecha), onclick: () => { F.dias = F.dias.filter((x) => x !== d); renderDias(); simular(); } }, '×'));
      }
      box.append(fila);
    });
  }

  function aplicarDefault(campo, valor) { F.dias.forEach((d) => { d[campo] = valor; }); renderDias(); }

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
    if (j === 'turnante') {
      if (F.dias.length) box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(F.dias.length, 'turno', 'turnos')), '. Los turnantes no usan cupo; coordinación autoriza igualmente.'));
      return;
    }
    if (!$('f-desde').value) return;
    const mi = ++F.seq;
    try {
      const s = await api('solicitudes/simular', { cuerpo: cuerpo() });
      if (mi !== F.seq) return;
      box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(s.habiles, 'día hábil', 'días hábiles') + ': '), textoFechas(s.dias)));
      (s.saldo || []).forEach((x) => {
        const nombre = x.saldo === 'FL' ? 'feriado legal' : 'administrativos';
        if (x.insuficiente) {
          F.sinSaldo = 'No te alcanza el saldo de ' + nombre + ' ' + x.anio + ': te quedan ' + AUS.num(x.disponible) + ' y esta solicitud usa ' + AUS.num(x.usa) + '. Habla con coordinación.';
          box.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, 'Saldo insuficiente. '), F.sinSaldo.replace(/^No te alcanza /, 'No alcanza ')));
        } else {
          box.append(h('p', { class: 'nota' }, 'Saldo de ' + nombre + ' ' + x.anio + ': usa ' + AUS.num(x.usa) + ', te quedarán ' + AUS.num(x.disponible - x.usa) + ' de ' + AUS.num(x.disponible) + ' disponibles.'));
        }
      });
      if (s.omitidos.length) box.append(h('p', { class: 'nota' }, 'Se omiten ' + AUS.plural(s.omitidos.length, 'día no hábil', 'días no hábiles') + ' (fin de semana o feriado): ' + s.omitidos.map((o) => AUS.fmt(o.fecha).slice(0, 5)).join(', ') + '.'));
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
      b.dias = F.dias.map((d) => ({ fecha: d.fecha, turno: d.turno, unidad_id: d.unidad_id, reemplazo: d.reemplazo || '' }));
      if (T().medio_dia) b.fraccion = F.fraccion;
      if (T().es_cambio_turno) Object.assign(b, { cambio_con: $('fc-con').value.trim(), devolucion_fecha: $('fc-fecha').value || null, devolucion_turno: $('fc-turno').value || null });
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
    if (!$('f-desde').value) return fallo('Indica la fecha.');
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
    $('fd-turno').onchange = (e) => aplicarDefault('turno', e.target.value);
    $('fd-unidad').onchange = (e) => aplicarDefault('unidad_id', +e.target.value || 0);
    $('fd-reemplazo').oninput = (e) => aplicarDefault('reemplazo', e.target.value);
    AUS.datalistNomina($('fd-reemplazo'));
    AUS.datalistNomina($('fc-con'));
    $('fs-acepta').onchange = () => mostrarEl($('fs-wj'), $('fs-acepta').checked);
    $('form').addEventListener('submit', enviar);
  }

  function abrirForm(q) {
    initForm();
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
        fila.append(h('div', { class: 'tarjeta-blanca' },
          h('p', { class: 'etiqueta' }, n),
          h('p', { class: 'cod-grande' }, AUS.num(x.disponible), h('span', { class: 'nota' }, ' días disponibles')),
          h('p', { class: 'meta' }, 'Asignados ' + AUS.num(x.asignado) + (x.ajustes ? ' · ajustes ' + (x.ajustes > 0 ? '+' : '') + AUS.num(x.ajustes) : '') +
            ' · autorizados ' + AUS.num(x.autorizado) + ' · pendientes ' + AUS.num(x.pendiente))));
      });
      caja.append(fila, h('p', { class: 'nota' }, 'Saldo interno del equipo: si no coincide con RR.HH., avisa a coordinación. Las solicitudes pendientes ya se descuentan.'));
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
    else if (path === '/ok' && ULTIMA) vista = 'ok';
    mostrarEl($('v-' + vista), true);
    ['cal', 'mis', 'perfil'].forEach((v) => $('nav-' + v).toggleAttribute('aria-current', false));
    if (['cal', 'mis', 'perfil'].includes(vista)) $('nav-' + vista).setAttribute('aria-current', 'page');
    if (vista === 'cal') abrirCal();
    else if (vista === 'pedir') abrirForm(q);
    else if (vista === 'mis') abrirMis();
    else if (vista === 'perfil') { initPerfil(); abrirPerfil(); }
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
