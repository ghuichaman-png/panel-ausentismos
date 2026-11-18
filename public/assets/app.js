/* Vista pública: calendario, formulario "Pedir días" y "Mis solicitudes". */
(function () {
  'use strict';
  const { h, api, vaciar } = AUS;
  const $ = (id) => document.getElementById(id);
  const mostrarEl = (el, v) => el.classList.toggle('oculto', !v);
  const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
  let CFG = null;
  let ULTIMA = null;      // última solicitud enviada (para la pantalla de confirmación)
  let CODIGO_PREFILL = ''; // código a mostrar al abrir "Mis solicitudes"

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
  const F = { persona: null, fuera: false, tipo: null, fraccion: 'completo', dias: [], llenos: [], seq: 0 };

  const jornada = () => (F.persona ? F.persona.jornada : F.fuera ? document.querySelector('input[name="ff-jornada"]:checked').value : null);
  const unidades = () => CFG.equipos.filter((e) => e.tiene_turnantes);

  function opcionesSelect(sel, items, vacio) {
    vaciar(sel);
    if (vacio) sel.append(h('option', { value: '' }, vacio));
    items.forEach((e) => sel.append(h('option', { value: e.id }, e.nombre)));
  }

  function resetForm() {
    Object.assign(F, { persona: null, fuera: false, tipo: null, fraccion: 'completo', dias: [], llenos: [] });
    ['f-nombre', 'f-desde', 'f-hasta', 'f-comentario', 'ff-rut', 'fd-reemplazo', 'fc-con', 'fc-fecha', 'fs-just', 'f-web'].forEach((i) => { $(i).value = ''; });
    $('fs-acepta').checked = false;
    $('fc-turno').value = '';
    document.querySelector('input[name="ff-jornada"][value="diurno"]').checked = true;
    $('f-error').textContent = '';
    opcionesSelect($('fd-unidad'), unidades(), 'Elige la unidad…');
    opcionesSelect($('ff-equipo'), CFG.equipos.filter((e) => e.tiene_diurnos));
    $('f-desde').min = AUS.hoyISO();
    $('f-hasta').min = AUS.hoyISO();
    $('fc-fecha').min = AUS.hoyISO();
    mostrarEl($('f-ficha'), false);
    mostrarEl($('f-fuera'), false);
    renderTipos();
    actualizar();
  }

  function elegirPersona(p) {
    F.persona = p; F.fuera = false;
    $('f-nombre').value = p.nombre;
    $('fi-jornada').textContent = p.jornada === 'turnante' ? 'Turnante' : 'Diurno';
    $('fi-equipo').textContent = p.equipo || (p.jornada === 'turnante' ? 'Rota entre unidades' : '—');
    $('fi-calidad').textContent = '—';
    mostrarEl($('f-ficha'), true);
    mostrarEl($('f-fuera'), false);
    renderTipos();
    actualizar();
  }

  function renderTipos() {
    const j = jornada();
    const box = vaciar($('f-tipos'));
    mostrarEl($('f-tipo-box'), !!j);
    if (!j) { F.tipo = null; return; }
    const tipos = j === 'turnante' ? ['FL', 'ADM', 'CT'] : ['FL', 'ADM'];
    if (!tipos.includes(F.tipo)) F.tipo = null;
    tipos.forEach((t) => box.append(h('label', { class: 'opcion' },
      h('input', { type: 'radio', name: 'tipo', value: t, checked: F.tipo === t, onchange: () => { F.tipo = t; renderFracs(); actualizar(); } }),
      h('span', null, AUS.tipoLargo[t] + (t === 'FL' ? ' (feriado)' : '')))));
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
    mostrarEl($('f-fechas'), !!(j && t));
    mostrarEl($('w-hasta'), t === 'FL');
    mostrarEl($('f-frac-box'), t === 'ADM');
    mostrarEl($('w-comentario'), !!(j && t));
    mostrarEl($('f-turn'), j === 'turnante' && !!t); // FL, ADM y CT necesitan turno y unidad por fecha
    mostrarEl($('f-defaults'), j === 'turnante' && t === 'FL');
    mostrarEl($('f-ct'), j === 'turnante' && t === 'CT');
    $('l-desde').textContent = t === 'FL' ? 'Desde' : t === 'CT' ? 'Fecha del turno que cedes' : 'Fecha';
    if (j === 'turnante' && t) sincronizarDias(); else { F.dias = []; renderDias(); }
    simular();
  }

  // ----- Turnantes: una fila por día -----
  function sincronizarDias() {
    const t = F.tipo;
    const d = $('f-desde').value;
    let fechas = [];
    if (d) {
      if (t === 'FL') {
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
      if (F.tipo !== 'CT') {
        const r = h('input', { class: 'input reemp', type: 'text', maxlength: '120', placeholder: 'Quién te cubre', 'aria-label': 'Quién cubre el ' + AUS.fmt(d.fecha), value: d.reemplazo || '',
          oninput: (e) => { d.reemplazo = e.target.value; } });
        AUS.datalistNomina(r);
        fila.append(r);
      }
      if (F.tipo === 'FL' && F.dias.length > 1) {
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
    ocultarSobre();
    const j = jornada();
    if (!j || !F.tipo) return;
    if (j === 'turnante') {
      if (F.dias.length) box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(F.dias.length, 'turno', 'turnos')), '. Los turnantes no usan cupo; coordinación autoriza igualmente.'));
      return;
    }
    if (!$('f-desde').value || (!F.persona && !(F.fuera && $('ff-rut').value.trim().length >= 8))) return;
    const mi = ++F.seq;
    try {
      const s = await api('solicitudes/simular', { cuerpo: cuerpo() });
      if (mi !== F.seq) return;
      $('fi-calidad').textContent = cap(s.persona.calidad);
      box.append(h('div', { class: 'aviso aviso-info' }, h('b', null, AUS.plural(s.habiles, 'día hábil', 'días hábiles') + ': '), textoFechas(s.dias)));
      if (s.omitidos.length) box.append(h('p', { class: 'nota' }, 'Se omiten ' + AUS.plural(s.omitidos.length, 'día no hábil', 'días no hábiles') + ' (fin de semana o feriado): ' + s.omitidos.map((o) => AUS.fmt(o.fecha).slice(0, 5)).join(', ') + '.'));
      if (s.consumo_total === 0) box.append(h('p', { class: 'nota' }, 'Feriado legal largo (5 o más días hábiles): no usa cupo, pero aparece en el calendario.'));
      else box.append(h('p', { class: 'nota' }, 'Uso de cupo: ' + AUS.num(s.consumo_total) + ' (cupo diario del equipo: ' + AUS.num(s.cupo) + ').'));
      if (s.dias_llenos.length) {
        F.llenos = s.dias_llenos;
        box.append(h('div', { class: 'aviso aviso-precaucion' }, h('b', null, 'Sin cupo: '),
          s.dias_llenos.map((d) => AUS.fmt(d.fecha).slice(0, 5) + ' (' + AUS.num(d.ocupado) + ' de ' + AUS.num(d.cupo) + ')').join(', ') +
          '. Puedes cambiar las fechas o enviarla igualmente como solicitud sobre cupo con una justificación.'));
        mostrarSobre();
      } else box.append(h('div', { class: 'aviso aviso-normal' }, h('b', null, 'Hay cupo disponible'), ' en esas fechas (queda pendiente de autorización).'));
    } catch (e) {
      if (mi === F.seq) box.append(h('p', { class: 'msg-error' }, e.message));
    }
  }, 300);

  function cuerpo() {
    const j = jornada();
    const b = { web: $('f-web').value, tipo: F.tipo, comentario: $('f-comentario').value.trim() };
    if (F.persona) b.funcionario_id = F.persona.id;
    else {
      Object.assign(b, { nombre: $('f-nombre').value.trim(), rut: $('ff-rut').value.trim(), jornada: j, equipo_id: +$('ff-equipo').value || null, calidad: $('ff-calidad').value });
    }
    if (j === 'diurno') {
      b.fecha_inicio = $('f-desde').value;
      b.fecha_fin = F.tipo === 'FL' ? ($('f-hasta').value || b.fecha_inicio) : b.fecha_inicio;
      b.fraccion = F.tipo === 'ADM' ? F.fraccion : 'completo';
    } else {
      b.dias = F.dias.map((d) => ({ fecha: d.fecha, turno: d.turno, unidad_id: d.unidad_id, reemplazo: d.reemplazo || '' }));
      if (F.tipo === 'ADM') b.fraccion = F.fraccion;
      if (F.tipo === 'CT') Object.assign(b, { cambio_con: $('fc-con').value.trim(), devolucion_fecha: $('fc-fecha').value || null, devolucion_turno: $('fc-turno').value || null });
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
    if (!F.persona && !F.fuera) return fallo('Elige tu nombre de la lista, o pulsa «No estoy en la lista».');
    if (!F.tipo) return fallo('Elige qué necesitas.');
    if (!$('f-desde').value) return fallo('Indica la fecha.');
    if (F.llenos.length && !$('fs-acepta').checked) return fallo('Hay días sin cupo. Cambia las fechas o marca «Enviar igualmente como solicitud sobre cupo».');
    if ($('fs-acepta').checked && F.llenos.length && !$('fs-just').value.trim()) return fallo('Escribe la justificación para enviar sobre cupo.');
    const btn = $('f-enviar');
    btn.disabled = true;
    try {
      const r = await api('solicitudes', { cuerpo: cuerpo() });
      ULTIMA = r;
      CODIGO_PREFILL = F.persona ? '' : r.codigo;
      if (F.persona) AUS.guardar('aus_persona', F.persona);
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
    AUS.autocompletar($('f-nombre'), {
      onPick: elegirPersona,
      onClear: () => { if (F.persona) { F.persona = null; mostrarEl($('f-ficha'), false); renderTipos(); actualizar(); } },
    });
    $('f-noestoy').onclick = () => {
      F.persona = null; F.fuera = true;
      mostrarEl($('f-ficha'), false); mostrarEl($('f-fuera'), true);
      refrescarEquipoFuera(); renderTipos(); actualizar();
      $('ff-rut').focus();
    };
    const refrescarEquipoFuera = () => {
      const turn = jornada() === 'turnante';
      opcionesSelect($('ff-equipo'), CFG.equipos.filter((e) => (turn ? e.tiene_turnantes : e.tiene_diurnos)), turn ? '(Sin equipo fijo)' : null);
    };
    document.querySelectorAll('input[name="ff-jornada"]').forEach((r) => r.addEventListener('change', () => { refrescarEquipoFuera(); renderTipos(); actualizar(); }));
    ['f-desde', 'f-hasta'].forEach((i) => $(i).addEventListener('change', () => {
      if (i === 'f-desde' && $('f-hasta').value && $('f-hasta').value < $('f-desde').value) $('f-hasta').value = '';
      if (i === 'f-desde') $('f-hasta').min = $('f-desde').value || AUS.hoyISO();
      actualizar();
    }));
    ['ff-rut', 'ff-equipo', 'ff-calidad'].forEach((i) => $(i).addEventListener('input', simular));
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
    if (fecha && /^\d{4}-\d{2}-\d{2}$/.test(fecha) && fecha >= AUS.hoyISO()) $('f-desde').value = fecha;
    const p = AUS.leer('aus_persona', null);
    if (p && p.id) { elegirPersona(p); }
    setTimeout(() => { if (!F.persona) $('f-nombre').focus(); }, 50);
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

  // ═══════════ Mis solicitudes ═══════════
  const M = { init: false };

  function abrirMis() {
    if (!M.init) {
      M.init = true;
      AUS.autocompletar($('m-q'), { onPick: (p) => { AUS.guardar('aus_persona', p); cargarPersona(p); }, onClear: () => {} });
      $('m-form').addEventListener('submit', (ev) => {
        ev.preventDefault();
        const q = $('m-q').value.trim();
        if (/^AUS-\d{4}-\d{1,6}$/i.test(q)) cargarLista({ codigo: q.toUpperCase() });
        else { const p = AUS.leer('aus_persona', null); if (p && p.nombre === q) cargarPersona(p); else $('m-error').textContent = 'Elige tu nombre de la lista que aparece al escribir, o escribe tu código (AUS-2026-0001).'; }
      });
    }
    $('m-error').textContent = '';
    vaciar($('m-lista'));
    if (CODIGO_PREFILL) { $('m-q').value = CODIGO_PREFILL; cargarLista({ codigo: CODIGO_PREFILL }); CODIGO_PREFILL = ''; return; }
    const p = AUS.leer('aus_persona', null);
    if (p && p.id) { $('m-q').value = p.nombre; cargarPersona(p); }
  }

  const cargarPersona = (p) => cargarLista({ funcionario_id: p.id });

  async function cargarLista(params) {
    $('m-error').textContent = '';
    const cont = vaciar($('m-lista'));
    try {
      const r = await api('solicitudes', { params });
      if (!r.solicitudes.length) { cont.append(h('p', { class: 'aviso aviso-info' }, 'No encontramos solicitudes.')); return; }
      r.solicitudes.forEach((s) => cont.append(tarjetaSol(s, () => cargarLista(params))));
    } catch (e) { $('m-error').textContent = e.message; }
  }

  function tarjetaSol(s, recargar) {
    const fechas = s.fecha_inicio === s.fecha_fin ? AUS.fmt(s.fecha_inicio) : AUS.fmt(s.fecha_inicio) + ' al ' + AUS.fmt(s.fecha_fin);
    const card = h('article', { class: 'sol' },
      h('div', { class: 'sol-cab' }, h('span', { class: 'cod' }, s.codigo), AUS.chip(AUS.tipoTxt(s.tipo, s.fraccion), 'chip-neutro'), AUS.estadoChip(s.estado), s.sobre_cupo ? AUS.chip('Sobre cupo', 'chip-precaucion') : null),
      h('div', null, h('strong', null, s.nombre), ' · ' + fechas + (s.turno ? ' · turno ' + s.turno : '')));
    if (s.estado === 'rechazada') card.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, 'Motivo del rechazo: '), s.motivo || 'No se indicó motivo.'));
    if (s.estado === 'autorizada') card.append(h('div', { class: 'aviso aviso-normal' }, h('b', null, 'Autorizada.')));
    if (s.estado === 'pendiente') card.append(h('div', { class: 'aviso aviso-info' }, 'Esperando la revisión de coordinación.'));
    if (s.anulable) {
      const zona = h('div');
      const abrir = h('button', { type: 'button', class: 'btn btn-peligro btn-chico', onclick: () => {
        abrir.classList.add('oculto');
        const rut = h('input', { class: 'input', type: 'text', placeholder: 'Tu RUT (12.345.678-5)', 'aria-label': 'Tu RUT para confirmar', autocomplete: 'off' });
        const msg = h('p', { class: 'msg-error', role: 'alert' });
        const ok = h('button', { type: 'submit', class: 'btn btn-peligro btn-chico' }, 'Confirmar anulación');
        const f = h('form', { class: 'fila', onsubmit: async (ev) => {
          ev.preventDefault(); msg.textContent = ''; ok.disabled = true;
          try { await api('solicitudes/' + s.id + '/anular', { cuerpo: { rut: rut.value } }); AUS.toast('Solicitud anulada'); recargar(); }
          catch (e) { msg.textContent = e.message; ok.disabled = false; }
        } }, rut, ok, h('button', { type: 'button', class: 'btn btn-chico', onclick: () => { vaciar(zona); zona.append(abrir); abrir.classList.remove('oculto'); } }, 'Cancelar'));
        zona.append(f, msg);
        rut.focus();
      } }, 'Anular');
      zona.append(abrir);
      card.append(zona, h('p', { class: 'nota' }, 'Se puede anular hasta las 23:59 del día anterior a la primera fecha.'));
    }
    return card;
  }

  // ═══════════ Ruteo ═══════════
  function mostrar() {
    if (!CFG) return;
    const [path, qs] = (location.hash || '#/').slice(1).split('?');
    const q = new URLSearchParams(qs || '');
    ['cal', 'pedir', 'ok', 'mis'].forEach((v) => mostrarEl($('v-' + v), false));
    let vista = 'cal';
    if (path === '/pedir') vista = 'pedir';
    else if (path === '/mis') vista = 'mis';
    else if (path === '/ok' && ULTIMA) vista = 'ok';
    mostrarEl($('v-' + vista), true);
    $('nav-cal').toggleAttribute('aria-current', false);
    $('nav-mis').toggleAttribute('aria-current', false);
    if (vista === 'cal') $('nav-cal').setAttribute('aria-current', 'page');
    if (vista === 'mis') $('nav-mis').setAttribute('aria-current', 'page');
    if (vista === 'cal') abrirCal();
    else if (vista === 'pedir') abrirForm(q);
    else if (vista === 'mis') abrirMis();
    else abrirOk();
    window.scrollTo(0, 0);
  }

  async function iniciar() {
    try {
      CFG = await api('config');
    } catch (e) {
      $('main').prepend(h('p', { class: 'aviso aviso-critico', role: 'alert' }, e.message));
      return;
    }
    AUS.hoy = CFG.hoy;
    $('titulo').textContent = CFG.titulo;
    document.title = CFG.titulo;
    if (CFG.logo) { const l = $('logo'); l.src = CFG.logo; mostrarEl(l, true); }
    window.addEventListener('hashchange', mostrar);
    mostrar();
  }
  iniciar();
})();
