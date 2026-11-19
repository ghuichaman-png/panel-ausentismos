/* Vista de coordinación. Requiere sesión de una persona con rol coordinación (se ingresa en la app principal). */
(function () {
  'use strict';
  const { h, vaciar } = AUS;
  const $ = (id) => document.getElementById(id);
  const mostrarEl = (el, v) => el.classList.toggle('oculto', !v);
  const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
  const S = { equipos: [], sec: 'pend', cal: { mes: null, vista: null } };

  const SECCIONES = [['pend', 'Pendientes'], ['todas', 'Solicitudes'], ['cal', 'Calendario'], ['res', 'Resumen'], ['nom', 'Nómina'], ['eq', 'Equipos y cupos'], ['fer', 'Feriados'], ['exp', 'Exportar y respaldo']];

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

  async function cargarEquipos() { S.equipos = (await api('coord/equipos')).equipos; }
  const equiposActivos = () => S.equipos.filter((e) => e.activo);

  function pintarTabs() {
    const t = vaciar($('tabs'));
    SECCIONES.forEach(([k, n]) => t.append(h('button', { type: 'button', 'aria-current': S.sec === k ? 'page' : null, onclick: () => { S.sec = k; pintarTabs(); abrir(k); } }, n)));
  }

  function abrir(k) {
    const m = $('sec');
    vaciar(m);
    ({ pend: secSolicitudes, todas: secSolicitudes, cal: secCalendario, res: secResumen, nom: secNomina, eq: secEquipos, fer: secFeriados, exp: secExportar }[k])(m, k)
      .catch((e) => { if (e.status !== 401) m.append(h('p', { class: 'aviso aviso-critico', role: 'alert' }, e.message)); });
  }

  // ───────── Utilidades de UI ─────────
  const campo = (etq, inp, ayuda) => h('label', { class: 'campo' }, h('span', null, etq), inp, ayuda ? h('p', { class: 'ayuda' }, ayuda) : null);
  const selectEq = (lista, vacio, valor) => {
    const s = h('select', { class: 'input' }, vacio != null ? h('option', { value: '' }, vacio) : null, lista.map((e) => h('option', { value: e.id }, e.nombre)));
    if (valor != null) s.value = valor;
    return s;
  };
  const check = (txt, marcado) => { const i = h('input', { type: 'checkbox', checked: !!marcado }); return [h('label', null, i, txt), i]; };

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
      h('button', { type: 'button', class: 'btn', onclick: () => { c.mes = AUS.hoyISO().slice(0, 7); cargar(); } }, 'Hoy'), sel), ley, grid, det);
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
          h('b', null, 'Importación: '), r.creados + ' creados, ' + r.actualizados + ' actualizados, ' + r.errores.length + ' con error.',
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
