/* Vista de coordinación. Requiere sesión de una persona con rol coordinación (se ingresa en la app principal). */
(function () {
  'use strict';
  const { h, vaciar } = AUS;
  const $ = (id) => document.getElementById(id);
  const mostrarEl = (el, v) => el.classList.toggle('oculto', !v);
  const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
  const S = { equipos: [], sec: 'hoy', cal: { mes: null, vista: null } };

  const SECCIONES = [['hoy', 'Hoy'], ['pend', 'Pendientes'], ['todas', 'Solicitudes'], ['cal', 'Calendario'], ['res', 'Resumen'], ['tur', 'Turnos'], ['hx', 'Horas extra'], ['nom', 'Nómina'], ['sal', 'Saldos'], ['tip', 'Tipos'], ['eq', 'Equipos y cupos'], ['fer', 'Feriados'], ['aud', 'Registro de cambios'], ['exp', 'Exportar y respaldo']];

  // ───────── Sesión (la de la persona; el ingreso está en la app principal) ─────────
  const api = AUS.api;
  const irAIngreso = () => { location.href = AUS.base + './'; };

  async function entrar(sesion) {
    $('quien').textContent = sesion.persona.nombre;
    mostrarEl($('app'), true); mostrarEl($('salir'), true); mostrarEl($('quien'), true);
    const cfg = await AUS.api('config');
    AUS.hoy = cfg.hoy;
    S.tipos = cfg.tipos;
    document.title = 'Coordinación · ' + cfg.titulo;
    await cargarEquipos();
    pintarTabs();
    abrir(S.sec);
  }

  // En el celular las tablas pasan a tarjetas (app.css): cada celda lleva el nombre de su columna en data-label.
  function etiquetarTablas(raiz) {
    raiz.querySelectorAll('table.tabla').forEach((t) => {
      const cab = [...t.querySelectorAll('thead th')].map((th) => th.textContent.trim());
      t.querySelectorAll('tbody tr').forEach((tr) => [...tr.children].forEach((td, i) => { if (!td.hasAttribute('data-label')) td.dataset.label = cab[i] || ''; }));
    });
  }
  new MutationObserver(() => etiquetarTablas($('sec'))).observe($('sec'), { childList: true, subtree: true });

  async function cargarEquipos() { S.equipos = (await api('coord/equipos')).equipos; }
  const equiposActivos = () => S.equipos.filter((e) => e.activo);

  function pintarTabs() {
    const t = vaciar($('tabs'));
    SECCIONES.forEach(([k, n]) => t.append(h('button', { type: 'button', 'aria-current': S.sec === k ? 'page' : null, onclick: () => { S.sec = k; pintarTabs(); abrir(k); } }, n)));
  }

  function abrir(k) {
    const m = $('sec');
    vaciar(m);
    ({ hoy: secHoy, sal: secSaldos, tip: secTipos, aud: secAuditoria, pend: secSolicitudes, todas: secSolicitudes, cal: secCalendario, res: secResumen, tur: secTurnos, hx: secHorasExtra, nom: secNomina, eq: secEquipos, fer: secFeriados, exp: secExportar }[k])(m, k)
      .catch((e) => { if (e.status !== 401) m.append(h('p', { class: 'aviso aviso-critico', role: 'alert' }, e.message)); });
  }

  // ───────── Utilidades de UI ─────────
  const campo = (etq, inp, ayuda) => h('label', { class: 'campo' }, h('span', null, etq), inp, ayuda ? h('p', { class: 'ayuda' }, ayuda) : null);
  const selectEq = (lista, vacio, valor) => {
    const s = h('select', { class: 'input' }, vacio != null ? h('option', { value: '' }, vacio) : null, lista.map((e) => h('option', { value: e.id }, e.nombre)));
    if (valor != null) s.value = valor;
    return s;
  };
  const check = (txt, marcado) => { const i = h('input', { type: 'checkbox', checked: !!marcado }); return [h('label', { class: 'check' }, i, h('span', null, txt)), i]; };

  function dialogo(titulo, cuerpo, botones) {
    return new Promise((resolve) => {
      const dlg = h('dialog', { 'aria-labelledby': 'dlg-t' });
      const err = h('p', { class: 'msg-error', role: 'alert' });
      const cerrar = (v) => { dlg.close(); dlg.remove(); resolve(v); };
      const fila = h('div', { class: 'acciones' });
      botones.forEach((b) => fila.append(h('button', { type: 'button', class: 'btn ' + (b.clase || ''), onclick: async () => {
        err.textContent = '';
        try { const r = b.accion ? await b.accion(cerrar) : undefined; if (r !== false && !b.sinCerrar) cerrar(r === undefined ? b.valor : r); }
        catch (e) { err.textContent = e.message; }
      } }, b.texto)));
      dlg.append(h('h2', { id: 'dlg-t' }, titulo), h('div', { class: 'vista' }, cuerpo, err, fila));
      dlg.addEventListener('cancel', () => { dlg.remove(); resolve(null); });
      document.body.append(dlg);
      dlg.showModal();
    });
  }

  const confirmar = (titulo, texto, textoBoton, clase) => dialogo(titulo, h('p', null, texto), [
    { texto: 'Cancelar', valor: false }, { texto: textoBoton, clase: clase || 'btn-primario', valor: true }]);

  // ───────── Solicitudes (pendientes / todas) ─────────
  async function secSolicitudes(m, modo) {
    const pend = modo === 'pend';
    m.append(h('h1', null, pend ? 'Bandeja de pendientes' : 'Todas las solicitudes'),
      h('div', { class: 'acciones' },
        h('button', { type: 'button', class: 'btn btn-primario', onclick: () => registrarEnNombre('LIC').then((ok) => ok && abrir(S.sec)) }, 'Registrar licencia'),
        h('button', { type: 'button', class: 'btn', onclick: () => registrarEnNombre('').then((ok) => ok && abrir(S.sec)) }, 'Registrar en nombre de…')));
    const f = {
      estado: h('select', { class: 'input' }, ...[['', 'Todos'], ['pendiente', 'Pendiente'], ['autorizada', 'Autorizada'], ['rechazada', 'Rechazada'], ['anulada', 'Anulada']].map(([v, t]) => h('option', { value: v }, t))),
      equipo: selectEq(equiposActivos(), 'Todos'),
      jornada: h('select', { class: 'input' }, h('option', { value: '' }, 'Todas'), h('option', { value: 'diurno' }, 'Diurno'), h('option', { value: 'turnante' }, 'Turnante')),
      tipo: h('select', { class: 'input' }, h('option', { value: '' }, 'Todos'), ...S.tipos.map((t) => h('option', { value: t.sigla }, t.sigla + ' · ' + t.nombre))),
      desde: h('input', { type: 'date', class: 'input' }), hasta: h('input', { type: 'date', class: 'input' }),
      q: h('input', { type: 'search', class: 'input', placeholder: 'Nombre, RUT o código' }),
    };
    const [lSobre, cSobre] = check('Sobre cupo'); const [lFuera, cFuera] = check('Fuera de nómina'); const [lVenc, cVenc] = check('Vencidas sin resolver');
    const resultados = h('div', { class: 'vista' });
    if (pend) f.estado.value = 'pendiente';
    const params = () => {
      const p = { estado: pend ? 'pendiente' : f.estado.value, equipo_id: f.equipo.value, jornada: f.jornada.value, tipo: f.tipo.value, desde: f.desde.value, hasta: f.hasta.value, q: f.q.value.trim() };
      if (cSobre.checked) p.sobre_cupo = 1; if (cFuera.checked) p.fuera_nomina = 1; if (cVenc.checked) p.vencidas = 1;
      return p;
    };
    const cargar = async () => {
      vaciar(resultados);
      const r = await api('coord/solicitudes', { params: params() });
      resultados.append(h('p', { class: 'nota', 'aria-live': 'polite' }, r.solicitudes.length + ' solicitud(es)' + (r.limite ? ' (mostrando las primeras 300; afina los filtros)' : '') + '.'));
      if (!r.solicitudes.length) resultados.append(h('p', { class: 'aviso aviso-info' }, pend ? 'No hay solicitudes pendientes con esos filtros.' : 'Sin resultados.'));
      r.solicitudes.forEach((s) => resultados.append(tarjeta(s, cargar)));
    };
    const filtros = h('form', { class: 'tarjeta vista', onsubmit: (e) => { e.preventDefault(); cargar().catch((er) => resultados.append(h('p', { class: 'msg-error' }, er.message))); } },
      h('div', { class: 'filtros' },
        pend ? null : campo('Estado', f.estado), campo('Equipo / unidad', f.equipo), campo('Jornada', f.jornada), campo('Tipo', f.tipo),
        pend ? null : campo('Desde', f.desde), pend ? null : campo('Hasta', f.hasta), campo('Buscar', f.q)),
      h('div', { class: 'checks' }, lSobre, lFuera, pend ? lVenc : null, h('button', { type: 'submit', class: 'btn btn-primario' }, 'Filtrar')));
    m.append(filtros, resultados);
    await cargar();
  }

  function tarjeta(s, recargar) {
    const fechas = s.fecha_inicio === s.fecha_fin ? AUS.fmt(s.fecha_inicio) : AUS.fmt(s.fecha_inicio) + ' al ' + AUS.fmt(s.fecha_fin);
    const card = h('article', { class: 'sol' });
    card.append(h('div', { class: 'sol-cab' }, h('span', { class: 'cod' }, s.codigo), AUS.chip(AUS.tipoTxt(s.tipo, s.fraccion), 'chip-neutro'), AUS.estadoChip(s.estado),
      s.sobre_cupo ? AUS.chip('Sobre cupo', 'chip-precaucion') : null, s.fuera_de_nomina ? AUS.chip('Fuera de nómina', 'chip-info') : null,
      s.vencida ? AUS.chip('Vencida sin resolver', 'chip-critico') : null));
    card.append(h('div', null, h('strong', null, s.nombre), ' · ' + s.rut + ' · ' + cap(s.jornada) + (s.equipo_nombre ? ' · ' + s.equipo_nombre : '') + ' · ' + cap(s.calidad)));
    card.append(h('div', null, fechas + (s.tipo === 'ADM' ? ' · ' + AUS.tipoTxt(s.tipo, s.fraccion) : '') + ' · ' + AUS.plural(s.dias.length, 'día', 'días')));
    if (s.jornada === 'turnante') {
      const nombreEq = (id) => (S.equipos.find((e) => e.id === +id) || {}).nombre || '';
      card.append(h('ul', { class: 'lista' }, s.dias.map((d) => h('li', null, AUS.fmt(d.fecha) + ' · turno ' + d.turno + ' · ' + nombreEq(d.unidad_turno_id) + (d.reemplazo_nombre ? ' · cubre ' + d.reemplazo_nombre : '')))));
      if (s.tipo === 'CT') card.append(h('div', null, 'Cambia con ' + s.cambio_con + (s.devolucion_fecha ? ' · devuelve ' + AUS.fmt(s.devolucion_fecha) + ' (' + s.devolucion_turno + ')' : '')));
    } else if (s.ocupacion.length) {
      const cls = { lleno: 'chip-critico', parcial: 'chip-precaucion', libre: 'chip-normal' };
      const chips = s.ocupacion.slice(0, 14).map((o) => AUS.chip(AUS.fmt(o.fecha).slice(0, 5) + ': ' + AUS.textoBloques({ am: o.am, pm: o.pm, total: o.ocupado }, o.cupo), cls[AUS.estadoCupo(o.ocupado, o.cupo)]));
      if (s.ocupacion.length > 14) chips.push(AUS.chip('+' + (s.ocupacion.length - 14) + ' más', 'chip-neutro'));
      card.append(h('div', null, h('span', { class: 'etiqueta' }, 'Ocupación del equipo (incluye esta solicitud)'), h('div', { class: 'chips' }, chips)));
    }
    if (s.comentario) card.append(h('div', null, h('b', null, 'Comentario: '), s.comentario));
    if (s.sobre_cupo) card.append(h('div', { class: 'aviso aviso-precaucion' }, h('b', null, 'Justificación sobre cupo: '), s.justificacion_sobre_cupo || '—'));
    if (s.resuelto_por) card.append(h('div', { class: 'meta' }, 'Resuelta por ' + s.resuelto_por + ' el ' + s.resuelto_en + (s.motivo_resolucion ? ' · Motivo: ' + s.motivo_resolucion : '')));
    card.append(h('div', { class: 'meta' }, 'Creada ' + s.creado_en + ' · IP ' + (s.ip || '—') + ' · ' + (s.user_agent || '').slice(0, 90)));

    const acc = h('div', { class: 'acciones' });
    const ejecutar = async (fn) => { try { await fn(); recargar(); } catch (e) { if (e.status !== 401) AUS.toast(e.message); } };
    const post = (acc2, cuerpo) => api('coord/solicitudes/' + s.id + '/' + acc2, { cuerpo });
    const btn = (t, clase, fn) => acc.append(h('button', { type: 'button', class: 'btn btn-chico ' + clase, onclick: fn }, t));

    if (s.estado === 'pendiente') {
      btn('Autorizar', 'btn-primario', () => ejecutar(() => post('resolver', { accion: 'autorizar' })));
      btn('Rechazar', 'btn-peligro', () => pedirMotivo('Rechazar ' + s.codigo, 'Motivo del rechazo (lo verá el funcionario)').then((mot) => mot && ejecutar(() => post('resolver', { accion: 'rechazar', motivo: mot }))));
    } else if (s.estado === 'autorizada') {
      btn('Pasar a rechazada', 'btn-peligro', () => pedirMotivo('Rechazar ' + s.codigo, 'Motivo del rechazo').then((mot) => mot && ejecutar(() => post('revertir', { motivo: mot }))));
    } else if (s.estado === 'rechazada') {
      btn('Pasar a autorizada', 'btn-primario', () => ejecutar(() => revertirAAutorizada(s, post)));
    }
    if (s.estado === 'pendiente' || s.estado === 'autorizada') {
      btn('Anular', 'btn-peligro', async () => { if (await confirmar('Anular ' + s.codigo, 'La solicitud se anulará y liberará el cupo.', 'Anular', 'btn-peligro')) ejecutar(() => post('anular', {})); });
    }
    card.append(acc);
    return card;
  }

  /** Licencia (nace autorizada) o cualquier solicitud registrada por coordinación en nombre de una persona. */
  async function registrarEnNombre(siglaInicial) {
    const nomina = (await api('coord/nomina', { params: { activo: 1 } })).funcionarios;
    const persona = h('select', { class: 'input' }, h('option', { value: '' }, 'Elige a la persona…'),
      ...nomina.map((x) => h('option', { value: x.id }, x.nombre + ' · ' + cap(x.jornada) + (x.equipo ? ' · ' + x.equipo : ''))));
    const tipo = h('select', { class: 'input' }, ...S.tipos.filter((t) => !t.es_cambio_turno).map((t) => h('option', { value: t.sigla }, t.nombre)));
    if (siglaInicial) tipo.value = siglaInicial;
    const desde = h('input', { class: 'input', type: 'date' });
    const hasta = h('input', { class: 'input', type: 'date' });
    const frac = h('select', { class: 'input' }, h('option', { value: 'completo' }, 'Día completo'), h('option', { value: 'am' }, 'Mañana (AM)'), h('option', { value: 'pm' }, 'Tarde (PM)'));
    const comentario = h('textarea', { class: 'input', maxlength: '500' });
    const [lSobre, sobre] = check('Registrar igual si no hay cupo (sobre cupo)', false);
    const just = h('textarea', { class: 'input', maxlength: '1000', placeholder: 'Justificación (obligatoria si es sobre cupo)' });
    const wHasta = campo('Hasta', hasta), wFrac = campo('¿Cuánto?', frac);
    const pintar = () => {
      const t = S.tipos.find((x) => x.sigla === tipo.value) || {};
      wHasta.classList.toggle('oculto', t.rango !== 'rango');
      wFrac.classList.toggle('oculto', !t.medio_dia);
    };
    tipo.onchange = pintar; pintar();
    const nota = h('p', { class: 'nota' }, 'Las licencias quedan autorizadas al registrarlas. Las demás quedan pendientes en la bandeja. Para turnantes, desde aquí se registran tipos sin reemplazo (como la licencia); los demás los pide la persona con sus turnos.');
    return dialogo('Registrar en nombre de', h('div', { class: 'vista' }, campo('Persona', persona), campo('Tipo', tipo),
      h('div', { class: 'dos-col' }, campo('Desde', desde), wHasta), wFrac, campo('Comentario (opcional)', comentario), lSobre, just, nota), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Registrar', clase: 'btn-primario', accion: async () => {
        if (!persona.value) throw new Error('Elige a la persona.');
        if (!desde.value) throw new Error('Indica la fecha.');
        const t = S.tipos.find((x) => x.sigla === tipo.value) || {};
        const r = await api('coord/solicitudes/en-nombre-de', { cuerpo: {
          funcionario_id: +persona.value, tipo: tipo.value, fecha_inicio: desde.value,
          fecha_fin: t.rango === 'rango' ? (hasta.value || desde.value) : desde.value, fraccion: t.medio_dia ? frac.value : 'completo',
          comentario: comentario.value.trim(), acepta_sobre_cupo: sobre.checked, justificacion: just.value.trim() } });
        AUS.toast(r.codigo + ' registrada (' + r.estado + ')');
        return true;
      } }]);
  }

  function pedirMotivo(titulo, etiqueta) {
    const ta = h('textarea', { class: 'input', maxlength: '500' });
    return dialogo(titulo, campo(etiqueta, ta), [{ texto: 'Cancelar', valor: null },
      { texto: 'Confirmar', clase: 'btn-primario', accion: () => { if (!ta.value.trim()) throw new Error('El motivo es obligatorio.'); return ta.value.trim(); } }]);
  }

  async function revertirAAutorizada(s, post) {
    try { await post('revertir', {}); }
    catch (e) {
      if (!(e.data && e.data.codigo === 'sin_cupo')) throw e;
      const ta = h('textarea', { class: 'input', maxlength: '1000' });
      const llenos = e.data.dias_llenos.map((d) => AUS.fmt(d.fecha) + ' (' + AUS.bloquesTxt(d.bloques) + ': ' + AUS.num(d.ocupado) + ' de ' + AUS.num(d.cupo) + ')').join(', ');
      const just = await dialogo('Ya no hay cupo', h('div', { class: 'vista' }, h('p', { class: 'aviso aviso-precaucion' }, 'Sin cupo en: ' + llenos + '. Para autorizarla debe quedar como sobre cupo con justificación.'), campo('Justificación', ta)),
        [{ texto: 'Cancelar', valor: null }, { texto: 'Autorizar sobre cupo', clase: 'btn-primario', accion: () => { if (!ta.value.trim()) throw new Error('La justificación es obligatoria.'); return ta.value.trim(); } }]);
      if (!just) throw new Error('Cancelado.');
      await post('revertir', { acepta_sobre_cupo: true, justificacion: just });
    }
  }

  // ───────── Calendario ─────────
  async function secCalendario(m) {
    m.append(h('h1', null, 'Calendario de coordinación'));
    const c = S.cal;
    c.mes = c.mes || AUS.hoyISO().slice(0, 7);
    const sel = h('select', { class: 'input', 'aria-label': 'Vista' },
      h('optgroup', { label: 'Equipos (diurnos)' }, S.equipos.filter((e) => e.activo && e.tiene_diurnos).map((e) => h('option', { value: 'e:' + e.id }, e.nombre))),
      h('option', { value: 'todos' }, 'Todos los equipos'), h('option', { value: 'turn' }, 'Turnantes'));
    sel.value = c.vista || sel.options[0].value;
    const titulo = h('h2', { 'aria-live': 'polite' });
    const grid = h('div'); const ley = h('div'); const det = h('div', { 'aria-live': 'polite' });
    let data = null, dia = null;
    const vista = () => (sel.value === 'todos' ? { tipo: 'todos' } : sel.value === 'turn' ? { tipo: 'turn' } : { tipo: 'equipo', equipoId: +sel.value.slice(2) });
    const pintar = () => {
      titulo.textContent = AUS.mesTitulo(c.mes);
      AUS.calendario(grid, data, vista(), { seleccion: dia, onSelect: (f) => { dia = f; pintar(); } });
      vaciar(ley).append(AUS.leyenda(vista()));
      vaciar(det);
      if (dia && dia.startsWith(c.mes)) det.append(AUS.detalleDia(dia, data, vista(), true));
    };
    const cargar = async () => { data = await api('coord/calendario', { params: { mes: c.mes } }); pintar(); };
    const nav = (n) => () => { c.mes = AUS.mesAdd(c.mes, n); dia = null; cargar(); };
    sel.onchange = () => { c.vista = sel.value; if (data) pintar(); };
    m.append(h('div', { class: 'cal-ctrl' }, h('button', { type: 'button', class: 'btn', 'aria-label': 'Mes anterior', onclick: nav(-1) }, '‹'), titulo,
      h('button', { type: 'button', class: 'btn', 'aria-label': 'Mes siguiente', onclick: nav(1) }, '›'),
      h('button', { type: 'button', class: 'btn', id: 'c-hoy', onclick: () => { c.mes = AUS.hoyISO().slice(0, 7); cargar(); } }, 'Hoy')), h('div', { class: 'cal-vista' }, sel), ley, grid, det);
    await cargar();
  }

  // ───────── Resumen por persona ─────────
  async function secResumen(m) {
    m.append(h('h1', null, 'Resumen por persona'), h('p', { class: 'nota' }, 'Informativo: días registrados en este sistema. No es el saldo oficial de feriado ni de administrativos (eso sigue en RR.HH.).'));
    const anio = h('input', { type: 'number', class: 'input', min: '2020', max: '2100', value: AUS.hoyISO().slice(0, 4) });
    const q = h('input', { type: 'search', class: 'input', placeholder: 'Filtrar por nombre' });
    const tabla = h('div', { class: 'tabla-wrap' });
    let datos = [];
    const pintar = () => {
      const f = q.value.trim().toLowerCase();
      const v = (p, t, e) => AUS.num((p.d[t] || {})[e] || 0);
      const filas = datos.filter((p) => !f || p.nombre.toLowerCase().includes(f)).map((p) => h('tr', null,
        h('td', null, p.nombre), h('td', null, (p.equipo || '') + ' · ' + cap(p.jornada)), h('td', null, cap(p.calidad)),
        ...['FL', 'ADM', 'CT'].flatMap((t) => [h('td', { class: 'num-der' }, v(p, t, 'autorizada')), h('td', { class: 'num-der' }, v(p, t, 'pendiente'))]),
        h('td', { class: 'num-der' }, AUS.num(['FL', 'ADM', 'CT'].reduce((a, t) => a + ((p.d[t] || {}).rechazada || 0), 0)))));
      vaciar(tabla).append(h('table', { class: 'tabla' }, h('thead', null, h('tr', null, ...['Persona', 'Equipo · jornada', 'Calidad', 'FL aut.', 'FL pend.', 'ADM aut.', 'ADM pend.', 'CT aut.', 'CT pend.', 'Rechazadas'].map((t) => h('th', null, t)))), h('tbody', null, filas)));
      if (!filas.length) tabla.append(h('p', { class: 'nota' }, 'Sin datos para ese año.'));
    };
    const cargar = async () => { datos = (await api('coord/resumen', { params: { anio: anio.value } })).personas; pintar(); };
    anio.onchange = cargar; q.oninput = pintar;
    m.append(h('div', { class: 'filtros' }, campo('Año', anio), campo('Buscar', q)), tabla);
    await cargar();
  }

  // ───────── Turnos (calendario de turnos, SPEC-v2 §6.2.4) ─────────
  const unidadesTurno = () => equiposActivos().filter((e) => e.tiene_turnantes);
  const EST_TURNO = { normal: null, cubierto: ['Cubierto', 'chip-normal'], sin_cubrir: ['Sin cubrir', 'chip-critico'], cambio: ['Cambio', 'chip-info'] };

  async function secTurnos(m) {
    const mes = h('input', { class: 'input', type: 'month', value: S.turnosMes || AUS.hoyISO().slice(0, 7) });
    const unidad = selectEq(unidadesTurno(), 'Todas las unidades', S.turnosUnidad || '');
    const sinCubrir = h('div', { class: 'vista' });
    const tabla = h('div', { class: 'tabla-wrap' });
    const todos = (await api('coord/nomina', { params: { activo: 1 } })).funcionarios;
    const turnantes = todos.filter((x) => x.jornada === 'turnante');
    const cargar = async () => {
      S.turnosMes = mes.value; S.turnosUnidad = unidad.value;
      const sc = await api('coord/turnos/sin-cubrir');
      vaciar(sinCubrir);
      if (sc.turnos.length) sinCubrir.append(h('div', { class: 'aviso aviso-critico' }, h('b', null, AUS.plural(sc.turnos.length, 'turno sin cubrir', 'turnos sin cubrir') + ' en los próximos 30 días'),
        h('ul', { class: 'lista' }, sc.turnos.map((x) => h('li', null, AUS.fmtLargo(x.fecha) + ' · ' + cap(x.tipo) + ' · ' + x.unidad + ' · ' + x.titular + ' (' + (x.solicitud_tipo || '') + ')',
          h('button', { type: 'button', class: 'btn btn-chico btn-primario', onclick: () => cubrir(x, todos).then((ok) => ok && cargar()) }, 'Asignar reemplazo'))))));
      const r = await api('coord/turnos', { params: { mes: mes.value, unidad_id: unidad.value } });
      vaciar(tabla).append(r.turnos.length ? h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Fecha', 'Turno', 'Unidad', 'Titular', 'Estado', 'Cubre', ''].map((t) => h('th', null, t)))),
        h('tbody', null, r.turnos.map((x) => {
          const e = EST_TURNO[x.estado];
          return h('tr', null, h('td', null, AUS.fmt(x.fecha)), h('td', null, cap(x.tipo) + (x.es_extra ? ' (extra)' : '')), h('td', null, x.unidad), h('td', null, x.titular),
            h('td', null, e ? AUS.chip(e[0], e[1]) : '—', x.solicitud_codigo ? h('span', { class: 'nota' }, ' ' + x.solicitud_codigo) : null),
            h('td', null, x.cubierto_por ? x.cubierto_por + (x.cubierto_extra ? ' (extra)' : '') : '—'),
            h('td', null, h('div', { class: 'acciones' },
              x.estado === 'sin_cubrir' ? h('button', { type: 'button', class: 'btn btn-chico btn-primario', onclick: () => cubrir(x, todos).then((ok) => ok && cargar()) }, 'Cubrir') : null,
              x.estado === 'normal' ? h('button', { type: 'button', class: 'btn btn-chico btn-peligro', onclick: async () => {
                if (!(await confirmar('Borrar turno', 'Se borrará el turno ' + cap(x.tipo) + ' del ' + AUS.fmt(x.fecha) + ' de ' + x.titular + '.', 'Borrar', 'btn-peligro'))) return;
                try { await api('coord/turnos/' + x.id + '/eliminar', { cuerpo: {} }); cargar(); } catch (er) { AUS.toast(er.message); }
              } }, 'Borrar') : null)));
        }))) : h('p', { class: 'nota' }, 'No hay turnos cargados en ese mes' + (unidad.value ? ' para esa unidad' : '') + '. Usa «Cargar en lote».'));
    };
    mes.onchange = cargar; unidad.onchange = cargar;
    m.append(h('h1', null, 'Calendario de turnos'),
      h('div', { class: 'acciones' },
        h('button', { type: 'button', class: 'btn btn-primario', onclick: () => cargarLote(turnantes).then((ok) => ok && cargar()) }, 'Cargar en lote'),
        h('button', { type: 'button', class: 'btn', onclick: () => agregarTurno(turnantes).then((ok) => ok && cargar()) }, 'Agregar un turno')),
      sinCubrir, h('div', { class: 'tarjeta filtros' }, campo('Mes', mes), campo('Unidad', unidad)), tabla);
    await cargar();
  }

  const selTurnante = (lista) => h('select', { class: 'input' }, h('option', { value: '' }, 'Elige a la persona…'), ...lista.map((x) => h('option', { value: x.id }, x.nombre)));
  const selTipoTurno = () => h('select', { class: 'input' }, h('option', { value: 'largo' }, 'Largo'), h('option', { value: 'noche' }, 'Noche (fecha en que empieza)'));

  function agregarTurno(turnantes) {
    const fecha = h('input', { class: 'input', type: 'date' }), tipo = selTipoTurno(), unidad = selectEq(unidadesTurno()), titular = selTurnante(turnantes);
    const [lExtra, extra] = check('Es un turno extra', false);
    return dialogo('Agregar un turno', h('div', { class: 'vista' }, campo('Fecha', fecha), campo('Turno', tipo), campo('Unidad', unidad), campo('Titular', titular), lExtra), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Agregar', clase: 'btn-primario', accion: async () => {
        await api('coord/turnos', { cuerpo: { fecha: fecha.value, tipo: tipo.value, unidad_id: +unidad.value, titular_id: +titular.value, es_extra: extra.checked } });
        return true;
      } }]);
  }

  function cargarLote(turnantes) {
    const unidad = selectEq(unidadesTurno()), titular = selTurnante(turnantes);
    const desde = h('input', { class: 'input', type: 'date', value: AUS.hoyISO() }), hasta = h('input', { class: 'input', type: 'date' });
    const patron = h('input', { class: 'input', value: 'L, N, -, -', 'aria-describedby': 'ayuda-patron' });
    return dialogo('Cargar turnos en lote', h('div', { class: 'vista' }, campo('Unidad', unidad), campo('Titular', titular),
      h('div', { class: 'dos-col' }, campo('Desde (primer día del patrón)', desde), campo('Hasta', hasta)),
      campo('Patrón que se repite', patron), h('p', { class: 'ayuda', id: 'ayuda-patron' }, 'L = largo, N = noche, - = libre, separados por comas. Cuarto turno: «L, N, -, -». Se puede repetir sin duplicar turnos.')), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Cargar', clase: 'btn-primario', accion: async () => {
        const p = patron.value.split(',').map((x) => x.trim().toUpperCase()).map((x) => (x === 'L' ? 'largo' : x === 'N' ? 'noche' : ''));
        const r = await api('coord/turnos/lote', { cuerpo: { unidad_id: +unidad.value, titular_id: +titular.value, desde: desde.value, hasta: hasta.value, patron: p } });
        AUS.toast(r.creados + ' turnos creados' + (r.omitidos ? ', ' + r.omitidos + ' ya existían' : ''));
        return true;
      } }]);
  }

  function cubrir(x, todos) {
    const quien = h('select', { class: 'input' }, h('option', { value: '' }, 'Elige a quien cubre…'),
      ...todos.filter((p) => p.id !== x.titular_id).map((p) => h('option', { value: p.id }, p.nombre + ' · ' + cap(p.jornada))));
    const [lExtra, extra] = check('Lo hace como turno extra', true);
    return dialogo('Cubrir turno del ' + AUS.fmt(x.fecha), h('div', { class: 'vista' }, h('p', null, cap(x.tipo) + ' · ' + x.unidad + ' · titular ' + x.titular), campo('Quién lo cubre', quien), lExtra), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Asignar', clase: 'btn-primario', accion: async () => { await api('coord/turnos/' + x.id + '/cubrir', { cuerpo: { funcionario_id: +quien.value, es_extra: extra.checked } }); return true; } }]);
  }

  // ───────── Horas extra (SPEC-v2 §4.7) ─────────
  async function secHorasExtra(m) {
    const mes = h('input', { class: 'input', type: 'month', value: S.hxMes || AUS.hoyISO().slice(0, 7) });
    const cont = h('div', { class: 'tabla-wrap' });
    const enlace = h('a', { class: 'btn', href: '#' }, 'Exportar CSV (Excel)');
    const cargar = async () => {
      S.hxMes = mes.value;
      enlace.href = AUS.urlApi('coord/horas-extra/exportar', { mes: mes.value });
      const r = await api('coord/horas-extra', { params: { mes: mes.value } });
      vaciar(cont).append(r.personas.length ? h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Persona', 'Turnos', 'Horas diurnas', 'Horas festivas', 'Total'].map((t) => h('th', null, t)))),
        h('tbody', null, r.personas.map((p) => h('tr', null,
          h('td', null, h('details', null, h('summary', null, p.nombre), h('ul', { class: 'lista' }, p.turnos.map((t) => h('li', null,
            AUS.fmt(t.fecha) + ' · ' + cap(t.tipo) + ' · ' + t.unidad + ' · ' + t.motivo + ': ' + AUS.num(t.diurnas) + ' + ' + AUS.num(t.festivas) + ' h' + (t.manual ? ' (corregidas)' : '')))))),
          h('td', { class: 'num-der' }, p.turnos.length), h('td', { class: 'num-der' }, AUS.num(p.diurnas)), h('td', { class: 'num-der' }, AUS.num(p.festivas)), h('td', { class: 'num-der' }, AUS.num(p.total)))))) :
        h('p', { class: 'nota' }, 'No hay turnos extra en ese mes.'));
    };
    mes.onchange = cargar;
    m.append(h('h1', null, 'Horas extra'), h('p', { class: 'nota' }, 'Calculadas con las reglas del equipo (SPEC-v2 §4.7): referencia para RR.HH., que valida el pago.'),
      h('div', { class: 'tarjeta filtros' }, campo('Mes', mes), enlace), cont);
    await cargar();
  }

  // ───────── Nómina ─────────
  async function secNomina(m) {
    m.append(h('h1', null, 'Nómina de funcionarios'));
    const q = h('input', { type: 'search', class: 'input', placeholder: 'Buscar nombre o RUT' });
    const lista = h('div', { class: 'tabla-wrap' });
    const resultado = h('div');
    const archivo = h('input', { type: 'file', accept: '.csv,text/csv', class: 'solo-lector', id: 'csv-file' });
    const cargar = async () => {
      const r = await api('coord/nomina', { params: { q: q.value } });
      vaciar(lista).append(h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Nombre', 'RUT', 'Jornada', 'Equipo', 'Calidad', 'Teléfono', 'Cuenta', 'Activo', ''].map((t) => h('th', null, t)))),
        h('tbody', null, r.funcionarios.map((x) => h('tr', null, h('td', null, x.nombre, x.rol === 'coordinacion' ? [' ', AUS.chip('Coordinación', 'chip-info')] : null),
          h('td', null, x.rut), h('td', null, cap(x.jornada)), h('td', null, x.equipo || '—'), h('td', null, cap(x.calidad)),
          h('td', null, x.telefono || '—'), h('td', null, chipCuenta(x.cuenta)),
          h('td', null, x.activo ? 'Sí' : 'No'), h('td', null, h('div', { class: 'acciones' },
            h('button', { type: 'button', class: 'btn btn-chico', onclick: () => editarFuncionario(x).then((ok) => ok && cargar()) }, 'Editar'),
            h('button', { type: 'button', class: 'btn btn-chico', onclick: () => gestionarCuenta(x).then(() => cargar()) }, 'Cuenta…'))))))));
      lista.prepend(h('p', { class: 'nota' }, r.funcionarios.length + ' persona(s)' + (r.funcionarios.length >= 1000 ? ' (límite 1000)' : '') + '.'));
    };
    archivo.onchange = async () => {
      const f = archivo.files[0];
      if (!f) return;
      const texto = await f.text();
      archivo.value = '';
      vaciar(resultado);
      try {
        const r = await api('coord/nomina/importar', { cuerpo: { csv: texto } });
        resultado.append(h('div', { class: 'aviso ' + (r.errores.length ? 'aviso-precaucion' : 'aviso-normal') },
          h('b', null, 'Importación: '), r.creados + ' creados, ' + r.actualizados + ' actualizados, ' + (r.sin_cambios || 0) + ' sin cambios, ' + r.errores.length + ' con error.',
          r.errores.length ? h('ul', null, r.errores.map((e) => h('li', null, 'Línea ' + e.linea + ': ' + e.motivo))) : null));
        cargar();
      } catch (e) { resultado.append(h('p', { class: 'msg-error' }, e.message)); }
    };
    q.oninput = AUS.debounce(cargar, 250);
    m.append(h('div', { class: 'tarjeta vista' },
      h('p', { class: 'nota' }, 'CSV con columnas: nombre,rut,jornada,equipo,calidad y, opcional, telefono (separador coma o punto y coma). Si el RUT ya existe se actualiza.'),
      h('div', { class: 'acciones' }, h('label', { class: 'btn btn-primario', for: 'csv-file' }, 'Importar CSV'), archivo,
        h('button', { type: 'button', class: 'btn', onclick: () => editarFuncionario(null).then((ok) => ok && cargar()) }, 'Agregar persona')), resultado),
      campo('Buscar', q), lista);
    await cargar();
  }

  const ESTADO_CUENTA = {
    activa: ['Activa', 'chip-normal'], codigo_pendiente: ['Código entregado', 'chip-info'], codigo_vencido: ['Código vencido', 'chip-precaucion'],
    bloqueada: ['Bloqueada', 'chip-critico'], sin_cuenta: ['Sin activar', 'chip-neutro'],
  };
  const chipCuenta = (c) => AUS.chip(...(ESTADO_CUENTA[c] || ESTADO_CUENTA.sin_cuenta));

  /** Código de acceso, desbloqueo, cierre de sesiones y rol de una persona. */
  async function gestionarCuenta(x) {
    const msg = h('div', { class: 'vista', 'aria-live': 'polite' });
    const hacer = async (fn) => { vaciar(msg); try { await fn(); } catch (e) { msg.append(h('p', { class: 'msg-error' }, e.message)); } };
    const bCodigo = h('button', { type: 'button', class: 'btn btn-primario', onclick: () => hacer(async () => {
      if (x.cuenta === 'activa' && !(await confirmar('Código nuevo para ' + x.nombre, 'El PIN actual dejará de servir y se cerrarán sus sesiones. Úsalo si olvidó su PIN o perdió su celular.', 'Generar código', 'btn-primario'))) return;
      const r = await api('coord/nomina/' + x.id + '/codigo', { cuerpo: {} });
      x.cuenta = 'codigo_pendiente';
      msg.append(h('div', { class: 'aviso aviso-normal' },
        h('p', null, 'Código para ', h('b', null, x.nombre), ' (vence en ' + r.vence_dias + ' días, se muestra solo esta vez):'),
        h('p', { class: 'cod-grande' }, r.codigo),
        h('p', { class: 'nota' }, 'Entrégaselo en persona o por WhatsApp. En la app debe elegir «Activar con código», escribir su RUT y este código, y crear su PIN.')));
    }) }, x.cuenta === 'activa' ? 'Generar código nuevo (olvidó su PIN)' : 'Generar código de acceso');
    const bDesb = h('button', { type: 'button', class: 'btn', onclick: () => hacer(async () => { await api('coord/nomina/' + x.id + '/desbloquear', { cuerpo: {} }); msg.append(h('p', { class: 'aviso aviso-normal' }, 'Cuenta desbloqueada.')); }) }, 'Desbloquear');
    const bSes = h('button', { type: 'button', class: 'btn', onclick: () => hacer(async () => { const r = await api('coord/nomina/' + x.id + '/cerrar-sesiones', { cuerpo: {} }); msg.append(h('p', { class: 'aviso aviso-normal' }, AUS.plural(r.cerradas, 'sesión cerrada', 'sesiones cerradas') + '.')); }) }, 'Cerrar todas sus sesiones');
    const esCoord = x.rol === 'coordinacion';
    const bRol = h('button', { type: 'button', class: 'btn' + (esCoord ? ' btn-peligro' : ''), onclick: () => hacer(async () => {
      const r = await api('coord/nomina/' + x.id + '/rol', { cuerpo: { rol: esCoord ? 'funcionario' : 'coordinacion' } });
      x.rol = r.rol;
      msg.append(h('p', { class: 'aviso aviso-normal' }, r.rol === 'coordinacion' ? 'Ahora tiene rol de coordinación.' : 'Ya no tiene rol de coordinación.'));
    }) }, esCoord ? 'Quitar rol de coordinación' : 'Dar rol de coordinación');
    await dialogo('Cuenta de ' + x.nombre, h('div', { class: 'vista' },
      h('p', null, 'Estado: ', chipCuenta(x.cuenta)),
      h('div', { class: 'acciones' }, bCodigo, x.cuenta === 'bloqueada' ? bDesb : null, bSes, bRol), msg), [{ texto: 'Cerrar', valor: true }]);
  }

  async function editarFuncionario(x) {
    const nombre = h('input', { class: 'input', value: x ? x.nombre : '' });
    const rut = h('input', { class: 'input', value: x ? x.rut : '' });
    const jornada = h('select', { class: 'input' }, h('option', { value: 'diurno' }, 'Diurno'), h('option', { value: 'turnante' }, 'Turnante'));
    const eq = selectEq(equiposActivos(), '(sin equipo)', x ? x.equipo_id : '');
    const cal = h('select', { class: 'input' }, ['titular', 'contrata', 'honorarios'].map((c) => h('option', { value: c }, cap(c))));
    const tel = h('input', { class: 'input', type: 'tel', inputmode: 'tel', autocomplete: 'off', placeholder: '9 1234 5678', value: x && x.telefono ? x.telefono : '' });
    const [lAct, act] = check('Activo', x ? x.activo : true);
    if (x) { jornada.value = x.jornada; cal.value = x.calidad; }
    return dialogo(x ? 'Editar persona' : 'Agregar persona', h('div', null, campo('Nombre completo', nombre), campo('RUT', rut), campo('Jornada', jornada), campo('Equipo', eq, 'Obligatorio para diurnos; opcional para turnantes.'), campo('Calidad', cal),
      campo('Teléfono (opcional)', tel, 'Solo lo ve coordinación, para coordinar reemplazos.'), lAct), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Guardar', clase: 'btn-primario', accion: async () => {
        await api('coord/nomina' + (x ? '/' + x.id : ''), { cuerpo: { nombre: nombre.value, rut: rut.value, jornada: jornada.value, equipo_id: +eq.value || null, calidad: cal.value, telefono: tel.value, activo: act.checked } });
        return true;
      } }]);
  }

  // ───────── Equipos ─────────
  async function secEquipos(m) {
    m.append(h('h1', null, 'Equipos y cupos'), h('p', { class: 'nota' }, 'El cupo diurno es el máximo de personas ausentes por día (acepta 0,5). Los turnantes nunca consumen cupo.'));
    const tabla = h('div', { class: 'tabla-wrap' });
    const pintar = () => {
      vaciar(tabla).append(h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Orden', 'Equipo', 'Diurnos', 'Turnantes', 'Cupo diurno', 'Activo', ''].map((t) => h('th', null, t)))),
        h('tbody', null, S.equipos.map((e) => h('tr', null, h('td', null, e.orden), h('td', null, e.nombre), h('td', null, e.tiene_diurnos ? 'Sí' : 'No'), h('td', null, e.tiene_turnantes ? 'Sí' : 'No'),
          h('td', null, e.tiene_diurnos ? AUS.num(e.cupo_diurno) : '—'), h('td', null, e.activo ? 'Sí' : 'No'),
          h('td', null, h('button', { type: 'button', class: 'btn btn-chico', onclick: () => editarEquipo(e).then(async (ok) => { if (ok) { await cargarEquipos(); pintar(); } }) }, 'Editar')))))));
    };
    m.append(h('p', null, h('button', { type: 'button', class: 'btn btn-primario', onclick: () => editarEquipo(null).then(async (ok) => { if (ok) { await cargarEquipos(); pintar(); } }) }, 'Agregar equipo')), tabla);
    pintar();
  }

  function editarEquipo(e) {
    const nombre = h('input', { class: 'input', value: e ? e.nombre : '' });
    const cupo = h('input', { class: 'input', type: 'number', min: '0', max: '50', step: '0.5', value: e ? e.cupo_diurno : 2 });
    const orden = h('input', { class: 'input', type: 'number', min: '0', value: e ? e.orden : S.equipos.length + 1 });
    const [lD, d] = check('Tiene personal diurno', e ? e.tiene_diurnos : true);
    const [lT, t] = check('Tiene turnantes (aparece como unidad de turno)', e ? e.tiene_turnantes : false);
    const [lA, a] = check('Activo', e ? e.activo : true);
    return dialogo(e ? 'Editar equipo' : 'Agregar equipo', h('div', null, campo('Nombre', nombre), campo('Cupo diurno por día', cupo), campo('Orden de despliegue', orden), h('div', { class: 'checks' }, lD, lT, lA)), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Guardar', clase: 'btn-primario', accion: async () => {
        await api('coord/equipos' + (e ? '/' + e.id : ''), { cuerpo: { nombre: nombre.value, cupo_diurno: cupo.value, orden: +orden.value, tiene_diurnos: d.checked, tiene_turnantes: t.checked, activo: a.checked } });
        return true;
      } }]);
  }

  // ───────── Hoy ─────────
  async function secHoy(m) {
    const r = await api('coord/hoy');
    const ir = (k) => { S.sec = k; pintarTabs(); abrir(k); };
    m.append(h('h1', null, 'Hoy, ' + AUS.fmt(r.hoy)));
    const bloque = (titulo, n, vacio, items, pie) => h('section', { class: 'tarjeta vista' },
      h('h2', null, titulo + ' ', h('span', { class: 'chip chip-neutro' }, String(n))),
      n ? h('ul', { class: 'lista-hoy' }, items) : h('p', { class: 'nota' }, vacio), pie || null);
    m.append(h('div', { class: 'grilla-hoy' },
      bloque('Ausentes hoy', r.ausentes.length, 'Nadie está ausente hoy.',
        r.ausentes.map((a) => h('li', null, h('strong', null, a.nombre), ' · ' + a.etiqueta + (a.equipo ? ' · ' + a.equipo : '')))),
      bloque('Turnos sin cubrir (7 días)', r.sin_cubrir.length, 'No hay turnos sin cubrir.',
        r.sin_cubrir.map((t) => h('li', null, AUS.fmt(t.fecha) + ' · ' + cap(t.tipo) + (t.unidad ? ' · ' + t.unidad : '') + (t.titular ? ' · ' + t.titular : ''))),
        r.sin_cubrir.length ? h('button', { type: 'button', class: 'btn btn-chico', onclick: () => ir('tur') }, 'Asignar en Turnos') : null),
      bloque('Pendientes que vencen pronto', r.pendientes_pronto.length, 'No hay pendientes para los próximos 3 días.',
        r.pendientes_pronto.map((s) => h('li', null, h('strong', null, s.nombre), ' · ' + (s.etiqueta || s.tipo) + ' · ' + AUS.fmt(s.fecha_inicio) + ' ',
          s.vencida ? AUS.chip('Vencida', 'chip-critico') : null)),
        r.pendientes_pronto.length ? h('button', { type: 'button', class: 'btn btn-chico btn-primario', onclick: () => ir('pend') }, 'Ir a la bandeja') : null)));
  }

  // ───────── Saldos FL / ADM ─────────
  const normTxt = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

  async function secSaldos(m) {
    const anioAct = +String(AUS.hoy).slice(0, 4);
    const anio = h('select', { class: 'input' }, [anioAct - 1, anioAct, anioAct + 1].map((a) => h('option', { value: a }, String(a))));
    anio.value = anioAct;
    const q = h('input', { type: 'search', class: 'input', placeholder: 'Nombre o equipo' });
    const tabla = h('div', { class: 'tabla-wrap' });
    let datos = [];
    const disp = (x) => AUS.num(x.disponible) + ' de ' + AUS.num(x.asignado + x.ajustes);
    const pend = (x) => (x.pendiente ? h('span', { class: 'nota' }, ' (' + AUS.num(x.pendiente) + ' pend.)') : null);
    const pintar = () => {
      const t = normTxt(q.value.trim());
      const lista = datos.filter((p) => !t || normTxt(p.nombre + ' ' + (p.equipo || '')).includes(t));
      vaciar(tabla).append(h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Persona', 'Equipo', 'FL disponible', 'ADM disponible', ''].map((x) => h('th', null, x)))),
        h('tbody', null, lista.map((p) => h('tr', null, h('td', null, p.nombre), h('td', null, p.equipo || (p.jornada === 'turnante' ? 'Turnante' : '—')),
          h('td', null, disp(p.FL), pend(p.FL)), h('td', null, disp(p.ADM), pend(p.ADM)),
          h('td', null, h('button', { type: 'button', class: 'btn btn-chico', onclick: () => ajustarSaldo(p, +anio.value).then((ok) => ok && cargar()) }, 'Ajustar')))))));
    };
    const cargar = async () => { datos = (await api('coord/saldos', { params: { anio: anio.value } })).personas; pintar(); };
    anio.onchange = () => cargar().catch((e) => tabla.append(h('p', { class: 'msg-error' }, e.message)));
    q.oninput = pintar;
    m.append(h('h1', null, 'Saldos FL y ADM'),
      h('p', { class: 'nota' }, 'Cada año parte con FL 15 y ADM 6, sin arrastre. Los ajustes puntuales (p. ej. más días de FL) quedan en el registro de cambios.'),
      h('div', { class: 'tarjeta filtros' }, campo('Año', anio), campo('Buscar', q)), tabla);
    await cargar();
  }

  function ajustarSaldo(p, anio) {
    const saldo = h('select', { class: 'input' }, h('option', { value: 'FL' }, 'FL (feriado legal)'), h('option', { value: 'ADM' }, 'ADM (administrativo)'));
    const dias = h('input', { class: 'input', type: 'number', step: '0.5', min: '-60', max: '60', value: '1', inputmode: 'decimal' });
    const motivo = h('input', { class: 'input', maxlength: '200', placeholder: 'Ej.: días adicionales por antigüedad' });
    return dialogo('Ajustar saldo de ' + p.nombre, h('div', null, h('p', { class: 'nota' }, 'Año ' + anio + '. Usa un número negativo para descontar.'),
      campo('Saldo', saldo), campo('Días', dias), campo('Motivo', motivo, 'Queda registrado y la persona lo ve en Mi perfil.')), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Guardar ajuste', clase: 'btn-primario', accion: async () => {
        await api('coord/saldos/ajustar', { cuerpo: { funcionario_id: p.funcionario_id, anio, saldo: saldo.value, dias: dias.value, motivo: motivo.value } });
        return true;
      } }]);
  }

  // ───────── Tipos de ausentismo ─────────
  async function secTipos(m) {
    const tabla = h('div', { class: 'tabla-wrap' });
    const si = (b) => (b ? 'Sí' : 'No');
    const pintar = async () => {
      const tipos = (await api('coord/tipos')).tipos;
      vaciar(tabla).append(h('table', { class: 'tabla' },
        h('thead', null, h('tr', null, ...['Sigla', 'Nombre', 'Se ve como', 'Cupo', 'Saldo', 'Medio día', 'Lo pide', 'Activo', ''].map((x) => h('th', null, x)))),
        h('tbody', null, tipos.map((t) => h('tr', null, h('td', null, t.sigla), h('td', null, t.nombre), h('td', null, t.etiqueta_publica),
          h('td', null, t.consume_cupo ? (t.regla_cupo === 'fl' ? 'Sí (regla FL)' : 'Sí') : 'No'), h('td', null, t.saldo || '—'), h('td', null, si(t.medio_dia)),
          h('td', null, t.lo_pide === 'coordinacion' ? 'Coordinación' : 'La persona'), h('td', null, si(t.activo)),
          h('td', null, h('button', { type: 'button', class: 'btn btn-chico', onclick: () => editarTipo(t).then((ok) => ok && pintar()) }, 'Editar')))))));
    };
    m.append(h('h1', null, 'Tipos de ausentismo'),
      h('p', { class: 'nota' }, 'Nada se borra: un tipo que ya no se usa se desactiva. Cambiar un tipo no modifica solicitudes ya hechas.'),
      h('p', null, h('button', { type: 'button', class: 'btn btn-primario', onclick: () => editarTipo(null).then((ok) => ok && pintar()) }, 'Agregar tipo')), tabla);
    await pintar();
  }

  function editarTipo(t) {
    const fijo = !!(t && t.precargado);
    const sel = (opts, v) => { const s = h('select', { class: 'input', disabled: fijo }, opts.map(([a, b]) => h('option', { value: a }, b))); s.value = v; return s; };
    const sigla = h('input', { class: 'input', maxlength: '6', value: t ? t.sigla : '', disabled: !!t, autocapitalize: 'characters' });
    const nombre = h('input', { class: 'input', maxlength: '60', value: t ? t.nombre : '' });
    const etq = h('input', { class: 'input', maxlength: '30', value: t ? t.etiqueta_publica : '' });
    const orden = h('input', { class: 'input', type: 'number', min: '0', value: t ? t.orden : 50 });
    const cupo = sel([['normal', 'Sí, cupo normal'], ['fl', 'Sí, con la regla de FL'], ['no', 'No consume cupo']], t ? (t.consume_cupo ? t.regla_cupo : 'no') : 'normal');
    const saldo = sel([['', 'Ninguno'], ['FL', 'FL'], ['ADM', 'ADM']], t ? (t.saldo || '') : '');
    const pide = sel([['funcionario', 'La persona, desde la app'], ['coordinacion', 'Solo coordinación lo registra']], t ? t.lo_pide : 'funcionario');
    const rango = sel([['rango', 'Rango de fechas'], ['un_dia', 'Un solo día']], t ? t.rango : 'rango');
    const [lM, med] = check('Permite medio día (mañana o tarde)', t ? t.medio_dia : false);
    const [lR, rem] = check('Exige reemplazo en turnos', t ? t.exige_reemplazo : true);
    const [lA, act] = check('Activo', t ? t.activo : true);
    if (fijo) { med.disabled = true; rem.disabled = true; }
    return dialogo(t ? 'Editar ' + t.sigla : 'Agregar tipo', h('div', null,
      fijo ? h('p', { class: 'aviso aviso-info' }, 'Tipo precargado: solo se cambian nombre, etiqueta, orden y si está activo.') : null,
      campo('Sigla', sigla, t ? 'No se puede cambiar.' : 'De 2 a 6 letras o números.'), campo('Nombre', nombre),
      campo('Cómo lo ven los demás en el calendario', etq), campo('¿Consume cupo del equipo?', cupo), campo('Descuenta de un saldo', saldo),
      campo('¿Quién lo pide?', pide), campo('Fechas', rango), campo('Orden', orden), h('div', { class: 'checks' }, lM, lR, lA)), [
      { texto: 'Cancelar', valor: false },
      { texto: 'Guardar', clase: 'btn-primario', accion: async () => {
        await api('coord/tipos' + (t ? '/' + t.id : ''), { cuerpo: { sigla: sigla.value, nombre: nombre.value, etiqueta_publica: etq.value, orden: +orden.value,
          consume_cupo: cupo.value !== 'no', regla_cupo: cupo.value === 'fl' ? 'fl' : 'normal', saldo: saldo.value || null, lo_pide: pide.value, rango: rango.value,
          medio_dia: med.checked, exige_reemplazo: rem.checked, activo: act.checked } });
        S.tipos = (await api('config')).tipos;
        return true;
      } }]);
  }

  // ───────── Registro de cambios ─────────
  async function secAuditoria(m) {
    const q = h('input', { type: 'search', class: 'input', placeholder: 'Persona, acción o código' });
    const lista = h('div', { class: 'tabla-wrap' });
    const cargar = async () => {
      const r = await api('coord/auditoria', { params: { q: q.value.trim() } });
      vaciar(lista).append(h('p', { class: 'nota', 'aria-live': 'polite' }, r.registros.length + ' registro(s)' + (r.registros.length >= 300 ? ' (los 300 más recientes; afina la búsqueda)' : '') + '.'),
        h('table', { class: 'tabla' }, h('thead', null, h('tr', null, ...['Fecha', 'Quién', 'Acción', 'Detalle'].map((x) => h('th', null, x)))),
          h('tbody', null, r.registros.map((x) => h('tr', null, h('td', null, x.fecha.slice(0, 16)), h('td', null, x.quien), h('td', null, x.accion.replace(/_/g, ' ')), h('td', null, x.detalle || ''))))));
    };
    m.append(h('h1', null, 'Registro de cambios'), h('p', { class: 'nota' }, 'Todo lo que se crea, autoriza, ajusta o configura queda aquí con quién lo hizo. No se puede editar.'),
      h('form', { class: 'tarjeta filtros', onsubmit: (e) => { e.preventDefault(); cargar().catch((er) => lista.append(h('p', { class: 'msg-error' }, er.message))); } },
        campo('Buscar', q), h('button', { type: 'submit', class: 'btn btn-primario' }, 'Buscar')), lista);
    await cargar();
  }

  // ───────── Feriados ─────────
  async function secFeriados(m) {
    m.append(h('h1', null, 'Feriados'));
    const tabla = h('div', { class: 'tabla-wrap' });
    const msg = h('p', { class: 'msg-error', role: 'alert' });
    const ta = h('textarea', { class: 'input', placeholder: '2026-12-08 Inmaculada Concepción\n25-12-2026 Navidad' });
    const cargar = async () => {
      const r = await api('coord/feriados');
      vaciar(tabla);
      if (!r.feriados.length) tabla.append(h('p', { class: 'aviso aviso-precaucion' }, 'Aún no hay feriados cargados. Sin ellos, los feriados se cuentan como días hábiles para los diurnos. Pega la lista oficial del año arriba.'));
      else tabla.append(h('table', { class: 'tabla' }, h('thead', null, h('tr', null, h('th', null, 'Fecha'), h('th', null, 'Nombre'), h('th', null, ''))),
        h('tbody', null, r.feriados.map((f) => h('tr', null, h('td', null, AUS.fmt(f.fecha) + ' (' + AUS.DIAS[AUS.fecha(f.fecha).getDay()] + ')'), h('td', null, f.nombre),
          h('td', null, h('button', { type: 'button', class: 'btn btn-chico btn-peligro', onclick: async () => { try { await api('coord/feriados/eliminar', { cuerpo: { fecha: f.fecha } }); cargar(); } catch (e) { AUS.toast(e.message); } } }, 'Eliminar')))))));
    };
    m.append(h('form', { class: 'tarjeta vista', onsubmit: async (ev) => {
      ev.preventDefault(); msg.textContent = '';
      try { const r = await api('coord/feriados', { cuerpo: { texto: ta.value } }); AUS.toast(r.guardados + ' feriado(s) guardado(s)'); ta.value = ''; cargar(); } catch (e) { msg.textContent = e.message; }
    } }, campo('Pegar lista de feriados (una por línea: «aaaa-mm-dd Nombre» o «dd-mm-aaaa Nombre»)', ta), msg, h('button', { class: 'btn btn-primario', type: 'submit' }, 'Agregar')), tabla);
    await cargar();
  }

  // ───────── Exportar y respaldo ─────────
  async function secExportar(m) {
    m.append(h('h1', null, 'Exportar y respaldo'));
    const estado = h('select', { class: 'input' }, ...[['', 'Todos'], ['pendiente', 'Pendiente'], ['autorizada', 'Autorizada'], ['rechazada', 'Rechazada'], ['anulada', 'Anulada']].map(([v, t]) => h('option', { value: v }, t)));
    const eq = selectEq(equiposActivos(), 'Todos');
    const desde = h('input', { type: 'date', class: 'input' }); const hasta = h('input', { type: 'date', class: 'input' });
    const anio = h('input', { type: 'number', class: 'input', value: AUS.hoyISO().slice(0, 4) });
    const enlace1 = h('a', { class: 'btn btn-primario' }, 'Descargar solicitudes (CSV)');
    const enlace2 = h('a', { class: 'btn btn-primario' }, 'Descargar resumen por persona (CSV)');
    const act = () => {
      const p = { que: 'solicitudes' };
      if (estado.value) p.estado = estado.value; if (eq.value) p.equipo_id = eq.value; if (desde.value) p.desde = desde.value; if (hasta.value) p.hasta = hasta.value;
      enlace1.href = AUS.urlApi('coord/exportar', p);
      enlace2.href = AUS.urlApi('coord/exportar', { que: 'resumen', anio: anio.value });
    };
    [estado, eq, desde, hasta, anio].forEach((e) => e.addEventListener('change', act));
    act();
    m.append(h('div', { class: 'tarjeta vista' }, h('div', { class: 'filtros' }, campo('Estado', estado), campo('Equipo / unidad', eq), campo('Desde', desde), campo('Hasta', hasta)), enlace1),
      h('div', { class: 'tarjeta vista' }, campo('Año del resumen', anio), enlace2),
      h('div', { class: 'tarjeta vista' }, h('h2', null, 'Respaldo de la base de datos'),
        h('p', { class: 'nota' }, 'Descarga una copia consistente del archivo .sqlite. Guárdala en un lugar seguro: contiene RUT de funcionarios.'),
        h('a', { class: 'btn', href: AUS.urlApi('coord/respaldo') }, 'Descargar respaldo (.sqlite)')));
  }

  // ───────── Arranque ─────────
  $('salir').addEventListener('click', async () => {
    try { await AUS.api('auth/salir', { cuerpo: {} }); } catch (e) { /* ya cerrada */ }
    irAIngreso();
  });
  window.addEventListener('aus:sin-sesion', irAIngreso);   // la sesión venció o la cerraron
  (async function () {
    try {
      const s = await AUS.api('auth/sesion');
      if (!s.autenticado) { irAIngreso(); return; }
      AUS.csrf = s.csrf;
      if (s.persona.rol !== 'coordinacion') { mostrarEl($('sin-permiso'), true); return; }
      await entrar(s);
    } catch (e) { $('sec').append(h('p', { class: 'aviso aviso-critico', role: 'alert' }, e.message)); mostrarEl($('app'), true); }
  })();
})();
