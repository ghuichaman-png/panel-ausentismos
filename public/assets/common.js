/* Utilidades compartidas por la vista pública y la de coordinación. Sin dependencias. */
(function () {
  'use strict';
  const AUS = (window.AUS = {});
  AUS.base = document.documentElement.dataset.base || '';
  AUS.csrf = null;
  AUS.hoy = null;

  // ───────── API ─────────
  AUS.api = async function (ruta, opt) {
    opt = opt || {};
    const qs = opt.params ? '&' + new URLSearchParams(Object.entries(opt.params).filter(([, v]) => v !== '' && v != null && v !== false)).toString() : '';
    const headers = { Accept: 'application/json' };
    const init = { method: opt.metodo || (opt.cuerpo ? 'POST' : 'GET'), headers, credentials: 'same-origin' };
    if (opt.cuerpo !== undefined) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opt.cuerpo); }
    if (AUS.csrf) headers['X-CSRF-Token'] = AUS.csrf;
    let resp;
    try { resp = await fetch(AUS.base + 'api.php?r=' + ruta + qs, init); }
    catch (e) { const er = new Error('No hay conexión con el servidor. Revisa tu internet e intenta de nuevo.'); er.status = 0; throw er; }
    let data = null;
    try { data = await resp.json(); } catch (e) { /* respuesta no JSON */ }
    if (!resp.ok) {
      const er = new Error((data && data.error) || 'Ocurrió un error. Intenta de nuevo.');
      er.status = resp.status; er.data = data || {};
      // La sesión terminó (venció, la cerraron o se generó un código nuevo): la app vuelve a la pantalla de ingreso.
      if (er.data.codigo === 'sin_sesion' && !ruta.startsWith('auth/')) window.dispatchEvent(new CustomEvent('aus:sin-sesion'));
      throw er;
    }
    return data;
  };
  AUS.urlApi = (ruta, params) => AUS.base + 'api.php?r=' + ruta + (params ? '&' + new URLSearchParams(params).toString() : '');

  // ───────── DOM ─────────
  AUS.h = function (tag, attrs, ...kids) {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v == null || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k === 'text') e.textContent = v;
      else if (k === 'dataset') Object.assign(e.dataset, v);
      else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
      else if (['value', 'checked', 'selected', 'disabled', 'hidden'].includes(k)) e[k] = v;
      else e.setAttribute(k, v === true ? '' : v);
    }
    const add = (k) => {
      if (k == null || k === false) return;
      if (Array.isArray(k)) return k.forEach(add);
      e.append(k instanceof Node ? k : document.createTextNode(String(k)));
    };
    kids.forEach(add);
    return e;
  };
  AUS.vaciar = (el) => { while (el.firstChild) el.removeChild(el.firstChild); return el; };
  AUS.debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  AUS.toast = function (msg) {
    const t = AUS.h('div', { class: 'toast', role: 'status' }, msg);
    document.body.append(t);
    setTimeout(() => t.remove(), 3500);
  };
  AUS.guardar = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } };
  AUS.leer = (k, def) => { try { const v = localStorage.getItem(k); return v === null ? def : JSON.parse(v); } catch (e) { return def; } };

  // ───────── Fechas y textos ─────────
  AUS.MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  AUS.DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  const pad = (n) => String(n).padStart(2, '0');
  AUS.fmt = (iso) => (iso ? iso.slice(8, 10) + '-' + iso.slice(5, 7) + '-' + iso.slice(0, 4) : '');
  AUS.fecha = (iso) => new Date(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10));
  AUS.iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  AUS.fmtLargo = (iso) => { const d = AUS.fecha(iso); return AUS.DIAS[d.getDay()] + ' ' + d.getDate() + ' de ' + AUS.MESES[d.getMonth()]; };
  AUS.addDias = (iso, n) => { const d = AUS.fecha(iso); d.setDate(d.getDate() + n); return AUS.iso(d); };
  AUS.hoyISO = () => AUS.hoy || new Date().toLocaleDateString('en-CA', { timeZone: 'America/Santiago' });
  AUS.mesAdd = (ym, n) => { const d = new Date(+ym.slice(0, 4), +ym.slice(5, 7) - 1 + n, 1); return d.getFullYear() + '-' + pad(d.getMonth() + 1); };
  AUS.mesTitulo = (ym) => AUS.MESES[+ym.slice(5, 7) - 1] + ' ' + ym.slice(0, 4);
  AUS.esFinde = (iso) => { const w = AUS.fecha(iso).getDay(); return w === 0 || w === 6; };
  AUS.num = (n) => String(Math.round(n * 100) / 100).replace('.', ',');
  AUS.plural = (n, s, p) => n + ' ' + (n === 1 ? s : p);

  // Texto corto de un tipo: su etiqueta pública (o la sigla) más la fracción si es medio día.
  AUS.tipoTxt = function (tipo, fr, etiqueta) {
    const base = etiqueta || tipo;
    const media = { am: ' ½ AM', pm: ' ½ PM', primera_mitad: ' ½ 1.ª mitad', segunda_mitad: ' ½ 2.ª mitad' }[fr];
    return base + (media || '');
  };
  AUS.estadoChip = function (estado) {
    const m = { pendiente: ['chip-info', 'Pendiente'], autorizada: ['chip-normal', 'Autorizada'], rechazada: ['chip-critico', 'Rechazada'], anulada: ['chip-neutro', 'Anulada'] }[estado] || ['chip-neutro', estado];
    return AUS.h('span', { class: 'chip ' + m[0] }, m[1]);
  };
  AUS.chip = (txt, clase) => AUS.h('span', { class: 'chip ' + (clase || 'chip-neutro') }, txt);

  // ───────── Autocompletado de nómina ─────────
  AUS.autocompletar = function (input, opt) {
    const lista = AUS.h('ul', { class: 'sugerencias oculto', role: 'listbox', id: 'sug-' + Math.random().toString(36).slice(2, 8) });
    input.parentNode.classList.add('ac');
    input.parentNode.append(lista);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', lista.id);
    input.setAttribute('autocomplete', 'off');
    let items = [], idx = -1, seq = 0;
    const cerrar = () => { lista.classList.add('oculto'); input.setAttribute('aria-expanded', 'false'); idx = -1; };
    const marcar = (i) => { idx = i; [...lista.children].forEach((li, j) => li.setAttribute('aria-selected', String(j === i))); };
    const elegir = (it) => { input.value = it.nombre; cerrar(); opt.onPick(it); };
    const pintar = () => {
      AUS.vaciar(lista);
      if (!items.length) { lista.append(AUS.h('li', { role: 'option', 'aria-disabled': 'true' }, 'Sin coincidencias')); }
      items.forEach((it, i) => lista.append(AUS.h('li', { role: 'option', onmousedown: (ev) => { ev.preventDefault(); elegir(it); } },
        AUS.h('span', null, it.nombre), AUS.h('small', null, (it.jornada === 'turnante' ? 'Turnante' : 'Diurno') + (it.equipo ? ' · ' + it.equipo : '')))));
      lista.classList.remove('oculto');
      input.setAttribute('aria-expanded', 'true');
    };
    const buscar = AUS.debounce(async () => {
      const q = input.value.trim();
      if (q.length < (opt.min || 2)) { cerrar(); return; }
      const mi = ++seq;
      try {
        const r = await AUS.api('nomina/buscar', { params: { q } });
        if (mi !== seq) return;
        items = r.resultados; idx = -1; pintar();
      } catch (e) { cerrar(); }
    }, 180);
    input.addEventListener('input', () => { if (opt.onClear) opt.onClear(); buscar(); });
    input.addEventListener('blur', () => setTimeout(cerrar, 120));
    input.addEventListener('keydown', (ev) => {
      if (lista.classList.contains('oculto')) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(Math.min(items.length - 1, idx + 1)); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(Math.max(0, idx - 1)); }
      else if (ev.key === 'Enter' && idx >= 0) { ev.preventDefault(); elegir(items[idx]); }
      else if (ev.key === 'Escape') cerrar();
    });
  };

  // Sugerencias de nombres para campos de texto libre (reemplazo, "con quién cambia")
  AUS.datalistNomina = function (input) {
    let dl = document.getElementById('dl-nomina');
    if (!dl) { dl = AUS.h('datalist', { id: 'dl-nomina' }); document.body.append(dl); }
    input.setAttribute('list', 'dl-nomina');
    let seq = 0;
    input.addEventListener('input', AUS.debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) return;
      const mi = ++seq;
      try {
        const r = await AUS.api('nomina/buscar', { params: { q } });
        if (mi !== seq) return;
        AUS.vaciar(dl);
        r.resultados.forEach((x) => dl.append(AUS.h('option', { value: x.nombre })));
      } catch (e) { /* sin sugerencias */ }
    }, 200));
  };

  // ───────── Calendario ─────────
  const EPS = 0.0001;
  const porDia = (data) => {
    const m = {};
    data.ausencias.forEach((a) => { (m[a.fecha] = m[a.fecha] || []).push(a); });
    return m;
  };
  AUS.equipoPorId = (data, id) => data.equipos.find((e) => e.id === id);
  // Ocupación de un equipo en un día, por bloque (SPEC-v2 §4.2). total = el bloque más ocupado.
  AUS.ocupacion = function (lista, equipoId) {
    let am = 0, pm = 0, pend = 0, ceros = 0;
    lista.forEach((a) => {
      if (a.jornada !== 'diurno' || a.equipo_id !== equipoId) return;
      if (a.consumo <= 0) ceros++;
      am += a.am || 0; pm += a.pm || 0;
      if (a.estado === 'pendiente') pend += (a.am || 0) + (a.pm || 0);
    });
    return { am, pm, total: Math.max(am, pm), pend, ceros };
  };
  // Texto de ocupación por bloque: «1 de 2» si mañana y tarde coinciden; si no, «M 2 · T 1».
  AUS.textoBloques = function (oc, cupo) {
    if (Math.abs(oc.am - oc.pm) < EPS) return AUS.num(oc.total) + ' de ' + AUS.num(cupo);
    return 'M ' + AUS.num(oc.am) + ' · T ' + AUS.num(oc.pm);
  };
  AUS.ariaBloques = (oc, cupo) => 'mañana ' + AUS.num(oc.am) + ' de ' + AUS.num(cupo) + ', tarde ' + AUS.num(oc.pm) + ' de ' + AUS.num(cupo);
  AUS.bloquesTxt = (b) => (b.length === 2 ? 'mañana y tarde' : b[0] === 'am' ? 'mañana' : 'tarde');
  AUS.estadoCupo = function (total, cupo) {
    if (cupo <= EPS || total >= cupo - EPS) return 'lleno';
    return total > EPS ? 'parcial' : 'libre';
  };

  /**
   * Dibuja el mes. vista = {tipo:'equipo'|'todos'|'turn', equipoId}. onSelect(fecha) al tocar un día.
   */
  AUS.calendario = function (host, data, vista, opt) {
    opt = opt || {};
    AUS.vaciar(host);
    const mapa = porDia(data);
    const hoy = AUS.hoyISO();
    const eq = vista.tipo === 'equipo' ? AUS.equipoPorId(data, vista.equipoId) : null;
    const grid = AUS.h('div', { class: 'cal', 'aria-label': 'Calendario de ' + AUS.mesTitulo(data.mes) });
    ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'].forEach((d) => grid.append(AUS.h('div', { class: 'cal-cab', 'aria-hidden': 'true' }, d)));
    const primero = AUS.fecha(data.desde);
    const lead = (primero.getDay() + 6) % 7;
    for (let i = 0; i < lead; i++) grid.append(AUS.h('div', { class: 'dia vacio', 'aria-hidden': 'true' }));
    const ultimo = +data.hasta.slice(8, 10);
    for (let n = 1; n <= ultimo; n++) {
      const f = data.mes + '-' + pad(n);
      const lista = mapa[f] || [];
      const feriado = data.feriados[f];
      const noHabil = AUS.esFinde(f) || !!feriado;
      let clase = '', est = '', sub = '', aria = '', clic = true;

      if (vista.tipo === 'equipo') {
        if (noHabil) { clase = 'nohabil'; est = feriado ? 'Feriado' : ''; aria = feriado ? 'feriado: ' + feriado : 'no hábil'; clic = false; }
        else {
          const oc = AUS.ocupacion(lista, eq.id);
          const e = AUS.estadoCupo(oc.total, eq.cupo_diurno);
          const amLleno = AUS.estadoCupo(oc.am, eq.cupo_diurno) === 'lleno', pmLleno = AUS.estadoCupo(oc.pm, eq.cupo_diurno) === 'lleno';
          clase = (e === 'lleno' && !(amLleno && pmLleno)) ? 'parcial' : e;   // un solo bloque lleno: aún hay cupo en el otro
          est = e === 'libre' ? 'Libre' : (amLleno && pmLleno) ? 'Lleno' : amLleno ? 'Mañana llena' : pmLleno ? 'Tarde llena' : AUS.textoBloques(oc, eq.cupo_diurno);
          if (oc.pend > EPS) { clase += ' pend'; sub = 'pendiente'; }
          if (oc.ceros) sub = (sub ? sub + ' · ' : '') + '+' + oc.ceros + ' sin cupo';
          aria = (e === 'libre' ? 'libre, ' : (amLleno && pmLleno) ? 'lleno, ' : '') + AUS.ariaBloques(oc, eq.cupo_diurno);
          if (oc.pend > EPS) aria += ', con solicitudes pendientes';
          if (oc.ceros) aria += ', más ' + oc.ceros + ' ausente(s) que no usan cupo';
        }
      } else if (vista.tipo === 'todos') {
        const nombres = new Set(lista.map((a) => a.nombre + '|' + a.solicitud_id));
        let llenos = 0;
        data.equipos.filter((e) => e.tiene_diurnos).forEach((e) => {
          const oc = AUS.ocupacion(lista, e.id);
          if (oc.total > EPS && AUS.estadoCupo(oc.total, e.cupo_diurno) === 'lleno') llenos++;
        });
        const aus = nombres.size;
        if (noHabil && !aus) { clase = 'nohabil'; est = feriado ? 'Feriado' : ''; aria = feriado ? 'feriado: ' + feriado : 'no hábil'; clic = false; }
        else {
          clase = noHabil ? 'nohabil' : llenos ? 'lleno' : aus ? 'parcial' : 'libre';
          est = llenos ? AUS.plural(llenos, 'lleno', 'llenos') : aus ? aus + ' aus.' : 'Libre';
          sub = llenos ? aus + ' aus.' : '';
          aria = (llenos ? AUS.plural(llenos, 'equipo lleno', 'equipos llenos') + ', ' : '') + (aus ? AUS.plural(aus, 'ausente', 'ausentes') : 'sin ausentes');
        }
      } else {
        const t = lista.filter((a) => a.jornada === 'turnante');
        if (t.length) { clase = 'turn'; est = AUS.plural(t.length, 'aus.', 'aus.'); aria = AUS.plural(t.length, 'turnante ausente', 'turnantes ausentes'); }
        else { clase = feriado ? 'nohabil' : ''; est = feriado ? 'Feriado' : '—'; aria = feriado ? 'feriado: ' + feriado : 'sin ausencias de turnantes'; }
      }

      const btn = AUS.h('button', {
        type: 'button', class: 'dia ' + clase + (f === hoy ? ' hoy' : '') + (opt.seleccion === f ? ' sel' : ''),
        'aria-label': AUS.fmtLargo(f) + ', ' + aria + (f === hoy ? ' (hoy)' : ''), 'aria-disabled': clic ? null : 'true',
        dataset: { fecha: f },
        onclick: () => { if (clic && opt.onSelect) opt.onSelect(f); },
      }, AUS.h('span', { class: 'num' }, n), est && AUS.h('span', { class: 'est' }, est), sub && AUS.h('span', { class: 'sub' }, sub));
      grid.append(btn);
    }
    host.append(grid);
  };

  AUS.leyenda = function (vista) {
    const l = AUS.h('div', { class: 'leyenda', 'aria-label': 'Leyenda' });
    if (vista.tipo === 'equipo') {
      l.append(AUS.chip('Libre', 'chip-normal'), AUS.chip('Cupo parcial (ej. 1 de 2)', 'chip-precaucion'), AUS.chip('Lleno', 'chip-critico'),
        AUS.h('span', { class: 'chip chip-neutro' }, 'Borde punteado = hay pendientes'), AUS.chip('No hábil', 'chip-neutro'));
    } else if (vista.tipo === 'todos') {
      l.append(AUS.chip('Libre (sin ausentes)', 'chip-normal'), AUS.chip('Con ausentes (aus.)', 'chip-precaucion'), AUS.chip('Algún equipo lleno', 'chip-critico'), AUS.chip('No hábil', 'chip-neutro'));
    } else l.append(AUS.chip('Turnantes ausentes', 'chip-info'), AUS.chip('Feriado', 'chip-neutro'));
    return l;
  };

  // Detalle de un día: nombre, tipo y estado (más datos de coordinación si corresponde)
  AUS.detalleDia = function (fecha, data, vista, coord) {
    const lista = data.ausencias.filter((a) => a.fecha === fecha);
    const feriado = data.feriados[fecha];
    const caja = AUS.h('div', { class: 'detalle tarjeta' }, AUS.h('h3', null, AUS.fmtLargo(fecha) + ' · ' + AUS.fmt(fecha)));
    if (feriado) caja.append(AUS.h('p', { class: 'nota' }, 'Feriado: ' + feriado));
    const fila = (a) => {
      const li = AUS.h('li', null, AUS.h('strong', null, a.nombre), AUS.chip(AUS.tipoTxt(a.tipo, a.fraccion, a.etiqueta), 'chip-neutro'), AUS.estadoChip(a.estado));
      if (a.jornada === 'turnante') {
        const u = a.unidad_id ? (AUS.equipoPorId(data, a.unidad_id) || {}).nombre : '';
        const partes = [a.turno ? 'turno ' + a.turno : '', u, a.tipo === 'CT' ? (a.cambio_con ? 'cambia con ' + a.cambio_con : '') : (a.reemplazo ? 'cubre ' + a.reemplazo : '')].filter(Boolean);
        if (partes.length) li.append(AUS.h('span', { class: 'nota' }, partes.join(' · ')));
      } else if (a.consumo <= 0 && a.tipo === 'FL') li.append(AUS.h('span', { class: 'nota' }, 'FL largo, sin uso de cupo'));
      if (coord) {
        li.append(AUS.h('span', { class: 'nota' }, a.codigo + ' · ' + a.rut + ' · ' + a.calidad));
        if (a.sobre_cupo) li.append(AUS.chip('Sobre cupo', 'chip-precaucion'), AUS.h('span', { class: 'nota' }, 'Justificación: ' + (a.justificacion || '—')));
      }
      return li;
    };
    const bloque = (titulo, arr) => {
      caja.append(AUS.h('h3', { class: 'etiqueta' }, titulo));
      caja.append(arr.length ? AUS.h('ul', { class: 'lista' }, arr.map(fila)) : AUS.h('p', { class: 'nota' }, 'Sin ausencias.'));
    };
    if (vista.tipo === 'equipo') {
      const eq = AUS.equipoPorId(data, vista.equipoId);
      const arr = lista.filter((a) => a.jornada === 'diurno' && a.equipo_id === eq.id);
      const oc = AUS.ocupacion(lista, eq.id);
      caja.append(AUS.h('p', null, eq.nombre + ': ' + AUS.ariaBloques(oc, eq.cupo_diurno) + ' cupos ocupados.'));
      bloque('Ausentes del equipo', arr);
    } else if (vista.tipo === 'todos') {
      data.equipos.filter((e) => e.tiene_diurnos).forEach((e) => {
        const arr = lista.filter((a) => a.jornada === 'diurno' && a.equipo_id === e.id);
        if (arr.length) bloque(e.nombre + ' · ' + AUS.textoBloques(AUS.ocupacion(lista, e.id), e.cupo_diurno), arr);
      });
      const t = lista.filter((a) => a.jornada === 'turnante');
      if (t.length) bloque('Turnantes', t);
      if (!lista.length) caja.append(AUS.h('p', { class: 'nota' }, 'Nadie ausente este día.'));
    } else bloque('Turnantes ausentes', lista.filter((a) => a.jornada === 'turnante'));
    return caja;
  };
})();
