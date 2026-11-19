# SPEC v2 — Ausentismos y turnos · Kinesiología HPM

> Reemplaza a `SPEC.MD.md`, que queda como historial. Integra la app nueva (PHP + SQLite) y la anterior (React + Firebase) según `docs/analisis.md`, `docs/propuesta.md` y `docs/vital-evaluacion.md`, y las respuestas del 19-11-2026.
>
> Los puntos marcados **[a confirmar]** son interpretaciones mías de tus respuestas. Están reunidos en la §12. No se implementan hasta que los confirmes.

---

## 1. Contexto y propósito

El equipo de kinesiología del Hospital Puerto Montt tiene unos 70 funcionarios y va en aumento: alrededor de 25 son **turnantes** (cuarto turno: turno largo y turno de noche) y el resto son **diurnos**, repartidos en **19 equipos y unidades**. La app sirve para cuatro cosas:
1. que cada funcionario **pida sus días** de ausentismo desde el celular en menos de un minuto;
2. que todos vean en un **calendario** el cupo de cada día y quién falta;
3. que coordinación **autorice o rechace**, gestione el **calendario de turnos** y los **reemplazos**, registre **licencias** y calcule las **horas extra**;
4. que cada persona vea su **saldo interno** de feriado legal y administrativos.

Es una herramienta de **gestión interna**. **No reemplaza** el sistema institucional de RR.HH. Los saldos que lleva sirven para que el equipo se organice: si no coinciden con los registros oficiales, mandan los oficiales, y coordinación los ajusta aquí.

**Qué define el éxito:** que la gente la use. Con la sesión guardada en el celular, pedir un día no exige escribir nombre, RUT ni clave.

### Objetivos
- Calendario mensual de **ausencias y cupo**, por equipo, con el **cupo por bloque** (mañana y tarde).
- **Calendario de turnos** por unidad, para turnantes y para quienes cubren.
- Formulario "Pedir días" de un solo paso, ya identificado.
- "Mis solicitudes", "Mis turnos" y "Mi saldo".
- Vista de coordinación por **rol personal**: resolver, turnos y reemplazos, licencias, nómina, saldos, horas extra y exportación.
- La misma vista de coordinación incluye la configuración: equipos, cupos, **tipos de ausentismo**, feriados, roles y respaldos.

### No-objetivos (no construir)
- Integración con sistemas institucionales.
- Diagnósticos ni documentos de licencias médicas: solo se registran sus fechas.
- Cálculo oficial de pago de horas extra: la app calcula y exporta, y RR.HH. valida.
- Notificaciones por correo (fase 2; el código queda preparado, ver §11).
- Recuperación de acceso por correo.

### Anti-objetivos (sería un fracaso aunque "funcione")
- Que pedir un día exija entrar con RUT y PIN cada vez. La sesión dura 180 días en un dispositivo personal.
- Que alguien vea o modifique datos sin la sesión o el rol que corresponde.
- Que dos solicitudes simultáneas sobrepasen un cupo o un saldo por una condición de carrera.
- Que un saldo se descuente dos veces o quede en un estado a medias.
- Que la base de datos sea descargable desde una URL pública.
- Que un estado se comunique solo con color: siempre lleva una palabra.
- Que un error se oculte y la pantalla muestre "éxito".

---

## 2. Stack y hosting

- **Hosting:** DreamHost compartido. Sin Docker, sin Node en producción y sin procesos en segundo plano.
- **Backend:** PHP 8.1+ puro, sin frameworks ni Composer en producción.
- **Base de datos:** un archivo SQLite vía PDO, **fuera de la carpeta pública**. `PRAGMA journal_mode=WAL`, `foreign_keys=ON` y `busy_timeout`.
  - **Toda escritura que verifique cupo, saldo o turnos ocurre dentro de `BEGIN IMMEDIATE`.** Una resolución con todos sus efectos (estado, turnos, auditoría) es **una sola transacción**: o se aplica entera o no se aplica nada.
  - El esquema lleva versión (`PRAGMA user_version`) y migraciones numeradas que se pueden repetir.
- **Frontend:** HTML, CSS y JavaScript **sin paso de compilación**, con módulos por pantalla. Estilos con **Vital** (§8), servidos desde el mismo dominio: tokens, CSS de componentes y la fuente Figtree. Instalable en la pantalla de inicio (manifiesto web).
- **Despliegue:** GitHub Actions sube por SFTP a DreamHost en cada push a `main`. **Nunca** sube ni sobrescribe `config.php` ni la carpeta de datos. Repositorio: `ghuichaman-png/panel-ausentismos`.
- **Zona horaria:** `America/Santiago`. Fechas visibles como "jueves 21 nov" o `dd-mm-aaaa`; en la API, ISO.
- **Punto de control:** si la interfaz del calendario de turnos se vuelve inmanejable sin framework, se evalúa una librería pequeña cargada como módulo, sin compilación. Requiere tu aprobación.

---

## 3. Datos de dominio

### 3.1 Equipos y unidades (19, editables por coordinación)

Son los 17 de `SPEC.MD.md` §3.1 más **Oncología infantil** y **Neurología infantil** (diurnos, cupo 2). Cada equipo tiene: nombre, `tiene_diurnos`, `tiene_turnantes`, `cupo_diurno` (por bloque; admite 0,5), `activo` y `orden`. Las unidades con turnantes son las del calendario de turnos.

### 3.2 Nómina (funcionarios)

Cada funcionario tiene:
- `nombre` y `rut` (validado y único);
- `jornada` (`diurno` | `turnante`), `equipo_id` (obligatorio para diurnos) y `calidad` (`titular` | `contrata` | `honorarios`);
- `telefono`, visible **solo para coordinación**;
- `rol` (`funcionario` | `coordinacion`) y `activo`.

Se carga por CSV (`nombre,rut,jornada,equipo,calidad,telefono`) con upsert por RUT, y se edita uno por uno. **No existen las solicitudes "fuera de nómina"**: si alguien no está en la nómina, coordinación lo agrega.

### 3.3 Cuentas y sesiones
- `credenciales`: `funcionario_id`, `pin_hash` (`password_hash`), `codigo_hash` + `codigo_vence` (activación o recuperación, de un solo uso), `intentos_fallidos` y `bloqueado_hasta`.
- `sesiones`: `token_hash` (SHA-256 de un token aleatorio de 32 bytes), `funcionario_id`, `creada`, `ultimo_uso`, `vence`, `compartido` (bool), `user_agent` y `revocada`.

### 3.4 Feriados

Fecha y nombre, editables por coordinación. Valen para los días hábiles, el cupo, los saldos y las horas extra. No hay listas fijas en el código.

### 3.5 Tipos de ausentismo (configurables por coordinación)

Cada tipo tiene:

| Campo | Valores |
|---|---|
| `sigla`, `nombre` | p. ej. `FL`, "Feriado legal" |
| `consume_cupo` | sí / no (solo afecta a diurnos) |
| `regla_cupo` | `normal` (1 por bloque) o `fl` (regla de FL largo, §4.2) |
| `saldo` | `FL` / `ADM` / ninguno |
| `medio_dia` | sí / no |
| `lo_pide` | `funcionario` / `coordinacion` |
| `exige_reemplazo` | sí / no (turnantes) |
| `rango` | `un_dia` / `rango` |
| `etiqueta_publica` | lo que ven los demás en el calendario (p. ej. "Licencia") |
| `es_cambio_turno` | marca el tipo con el flujo de §4.4 |
| `activo` | sí / no |

**Precargados:**

| Sigla | Nombre | Cupo | Saldo | ½ día | Lo pide | Reemplazo (turnante) | Rango | Etiqueta |
|---|---|---|---|---|---|---|---|---|
| FL | Feriado legal | sí, regla `fl` | FL | no | funcionario | sí | rango | "FL" |
| ADM | Permiso administrativo | sí | ADM (½ = 0,5) | sí | funcionario | sí | un día | "ADM" / "ADM ½ AM/PM" |
| CT | Cambio de turno | no | ninguno | no | funcionario (solo turnantes) | — (es el cambio) | un turno | "CT" |
| LIC | Licencia médica | **no** | ninguno | no | **coordinación** | no (coordinación asigna) | rango | "Licencia" |

Los tipos precargados se pueden desactivar, pero no borrar. Un tipo con solicitudes no se borra: se desactiva.

### 3.6 Solicitudes y días
- `solicitudes`: como en `SPEC.MD.md` §3.4, con estos cambios:
  - **sin** `fuera_de_nomina`;
  - `tipo_id` en lugar de `tipo`;
  - `creada_por` (quién la ingresó: la persona o coordinación "en nombre de");
  - `resuelta_por` (`funcionario_id`, no texto libre).
- `solicitud_dias`: una fila por día (diurno) o por turno (turnante), con `bloque_am` y `bloque_pm` (consumo de cupo de cada bloque: 0, 0,5 o 1), `descuenta_saldo` (días: 0, 0,5 o 1), `turno_id` (turnante), `reemplazo_id` (funcionario que cubre) y `es_extra`.

### 3.7 Turnos
- `turnos`: `fecha` (la noche se registra con la fecha en que empieza), `tipo` (`largo` | `noche`), `unidad_id`, `titular_id` (a quien le corresponde) y `cubierto_por_id` (null = lo hace el titular).
- También: `es_extra`, `horas_override` (null = se calcula) y `origen` (`carga` | `ausencia` | `cambio`, con `solicitud_id`).
- Coordinación los crea uno por uno o **en lote** (patrón por unidad y mes), los edita y los borra si no tienen solicitudes asociadas.

### 3.8 Saldos (libro de movimientos)
- `saldo_movimientos`: `funcionario_id`, `anio`, `saldo` (`FL` | `ADM`), `dias` (positivo o negativo), `motivo` y `creado_por`. Hay dos clases:
  - **asignación anual** automática (regla fija: FL 15 y ADM 6);
  - **ajustes** de coordinación con motivo obligatorio (p. ej. "+5 FL por antigüedad").
- **El saldo no se guarda: se calcula.** disponible = asignación + ajustes − días de solicitudes **autorizadas** − días de solicitudes **pendientes** (las pendientes reservan saldo) **[a confirmar 12.6]**.

### 3.9 Auditoría

Fecha y hora, `funcionario_id` del actor, acción, solicitud o turno afectado, detalle e IP. Se registra cada ingreso, activación, cambio de PIN, creación, resolución, anulación, cambio de turnos, ajuste de saldo y cambio de configuración. Coordinación tiene una vista de "Registro de cambios".

---

## 4. Reglas de negocio

### 4.1 Quién puede pedir qué

| Tipo | Diurnos | Turnantes |
|---|---|---|
| FL | rango de días hábiles | rango de fechas; se cubre **cada turno cargado** del rango |
| ADM | día completo, o ½ AM / ½ PM | un turno completo, o primera / segunda mitad |
| CT | no disponible | sí |
| LIC | la registra coordinación | la registra coordinación |
| Tipos nuevos | según su configuración | según su configuración |

### 4.2 Cupo (solo diurnos), **por bloque**
- Los turnantes nunca consumen ni bloquean cupo.
- Cada equipo tiene un `cupo_diurno` por **bloque** (mañana y tarde), inicialmente 2.
- **Consumo por día hábil:**
  - día completo = 1 en AM **y** 1 en PM;
  - ½ AM = 1 en AM;
  - ½ PM = 1 en PM.

  Así, 3 personas de medio día AM en un equipo con cupo 2 dejan **lleno** el bloque AM.
- **Regla `fl`:** un FL de **menos de 5 días hábiles** consume 1 por bloque y día. Uno de **5 o más días hábiles** de un titular o contrata consume **0**, pero aparece igual en el calendario. Para honorarios consume **siempre** 1.
- Días hábiles de los diurnos = lunes a viernes que no estén en la tabla de feriados. Si el rango incluye otros días, se omiten y se informa cuántos quedaron.
- Ocupan cupo las solicitudes `pendiente` y `autorizada`. Las demás lo liberan.
- **Sobre cupo:** si un bloque queda lleno, se puede enviar como "sobre cupo" con justificación obligatoria. Coordinación lo ve destacado.
- La verificación se hace **en el servidor, dentro de la transacción**.
- La **licencia (LIC) no consume cupo**: se muestra, pero no limita a los demás.

### 4.3 Turnantes y calendario de turnos
- **Se exige turno cargado.** Un turnante solo puede pedir ADM o CT sobre un turno suyo del calendario. Para un FL elige un rango, y el sistema lista sus turnos de ese rango; el rango debe contener al menos uno **[a confirmar 12.1]**.
- Para cada turno afectado, la persona indica **quién lo cubre**, eligiendo de la nómina activa. Es obligatorio si el tipo exige reemplazo.
- Al **autorizar**, en la misma transacción, cada turno queda `cubierto_por = reemplazo`, con `es_extra` según lo que marque coordinación (por defecto, sí) **[a confirmar 12.4]**. Al **anular o revertir** una autorizada, se restauran **solo los turnos vinculados a esa solicitud**.
- Si coordinación registra una **licencia** de un turnante, sus turnos del rango quedan **"sin cubrir"**, destacados en coordinación para asignarles reemplazo.

### 4.4 Cambio de turno (solo turnantes)
- La persona coordinó antes con su compañero. La app registra el turno que cede (de su calendario) y **con quién** cambia (de la nómina), y opcionalmente el turno de **devolución** (un turno del compañero).
- **La contraparte no tiene que aceptar en la app.** La solicitud llega directo a coordinación.
- Al **autorizar**, en una sola transacción, se intercambian los titulares (y los de la devolución, si la hay). Al anular o revertir, se restauran.
- No consume cupo ni saldo.

### 4.5 Saldos
- Se llevan dos saldos: **FL** y **ADM**. El período es el **año calendario** y **no se arrastra** lo no usado.
- Al iniciar el año se asigna automáticamente **FL 15 y ADM 6** a cada persona activa. Coordinación puede **agregar días de FL en casos puntuales** (y ajustar cualquier saldo) con un motivo obligatorio, que queda auditado.
- **Qué descuenta:**
  - FL: **días hábiles** del rango, tanto para diurnos como para turnantes, aunque el turnante no tenga turno en todos esos días;
  - ADM: 1 por día o turno completo, 0,5 por medio día o mitad de turno;
  - un FL que cruza el cambio de año descuenta de cada año sus propios días.
- **Bloqueo:** si la solicitud supera el saldo disponible, **no se puede enviar**. El formulario dice cuántos días quedan. Coordinación puede registrarla "en nombre de" con un ajuste previo.
- Rechazar, anular o revertir deja de contar esos días automáticamente: el saldo se calcula, así que no hay devoluciones manuales ni doble descuento.

### 4.6 Licencias
- Solo coordinación las registra (rango de fechas, sin diagnóstico). Nacen **autorizadas**.
- En el calendario se ven como **"Licencia"**. No consumen cupo ni saldo.
- Afectan los turnos (§4.3) y se pueden anular con auditoría.

### 4.7 Horas extra
- Un turno cuenta como extra cuando lo cubre alguien que no es el titular y tiene `es_extra = sí`, o cuando se cargó como extra.
- **Cálculo** (portado de la app anterior; festivo = sábado, domingo o feriado de la tabla):

  | Caso | Horas |
  |---|---|
  | Turno largo en día hábil | 12 h diurnas |
  | Turno largo en festivo | 11 h festivas |
  | Diurno que cubre un turno largo en día hábil | 3 h extra (de lunes a jueves) o 4 h (viernes) |
  | Diurno que cubre ½ AM | 0 h |
  | Noche ordinaria | 2 h diurnas + 10 h festivas = 12 h |
  | Noche hacia un festivo | 1 + 12 = 13 h |
  | Noche desde un festivo hacia día hábil | 1 + 11 = 12 h |
  | Noche entre festivos | 0 + 13 = 13 h |
  | Turno de medio día | la mitad |
  | Corrección manual por turno | la reparte con las mismas reglas |

- Se ven por persona y mes (diurnas, festivas y total) y se **exportan a CSV**.

### 4.8 Estados, resolución y anulación

```
pendiente ──(coordinación autoriza)──> autorizada
pendiente ──(coordinación rechaza + motivo obligatorio)──> rechazada
pendiente | autorizada ──(dueño, hasta las 23:59 del día anterior al primer día; o coordinación)──> anulada
autorizada ↔ rechazada (coordinación revierte; al autorizar se re-verifican cupo, saldo y turnos)
```

- Se resuelve completa, no por días.
- **No existe "volver a pendiente" ni borrar solicitudes.**
- Solo **la persona dueña** de la solicitud (con su sesión) o coordinación pueden anular.
- Una persona no puede tener dos solicitudes activas superpuestas: mismo día y bloque, o mismo turno.
- No se puede pedir con fecha pasada. Coordinación sí puede registrar licencias o solicitudes pasadas "en nombre de".
- Las pendientes cuya fecha ya pasó se destacan como **"vencidas"**.

### 4.9 Tipos creados por coordinación

Se comportan según su configuración (§3.5), con las mismas reglas de cupo por bloque, saldo, estados y turnos. Cambiar la configuración de un tipo **no recalcula** solicitudes ya creadas. El consumo queda guardado en `solicitud_dias`.

---

## 5. Acceso (login por usuario, opción A)

- **Todos tienen cuenta**, y todo está detrás del login. No hay vistas públicas.
- **Activación:** coordinación genera un **código de activación** (8 caracteres sin ambiguos, válido 7 días, un solo uso) y lo entrega **uno a uno** en persona o por WhatsApp. La persona ingresa su RUT y el código, y crea un **PIN de 6 dígitos**. Se rechazan PIN triviales (000000, 123456, la fecha, etc.).
- **Ingreso:** RUT + PIN, con teclado numérico.
- **Sesión:** cookie `HttpOnly`, `Secure` y `SameSite=Lax` con un token opaco. En la base solo se guarda su hash.
  - En un dispositivo personal dura **180 días** desde el último uso.
  - Con la casilla **"computador compartido"**, la cookie dura hasta cerrar el navegador y la sesión vence a los **30 minutos sin uso**.
  - Desde su perfil, cada persona ve y cierra sus sesiones; coordinación puede cerrarlas todas.
- **Recuperación:** coordinación genera un **código nuevo**, lo que invalida el PIN anterior. Sin correo.
- **Protección:** bloqueo **por cuenta** tras 5 PIN errados (15 minutos, luego 1 hora y después hasta que coordinación lo desbloquee). Límite por IP **alto** (p. ej. 300 intentos por hora), solo contra ataques masivos, porque todo el hospital comparte una IP. CSRF en toda escritura. Límite de creación de solicitudes **por cuenta**.
- **Roles:**
  - **funcionario:** sus datos, el calendario, pedir y anular las propias;
  - **coordinación:** **todos los equipos**, resolver, turnos, licencias, nómina, saldos, códigos, "en nombre de" y la configuración (equipos, cupos, tipos, feriados, roles y respaldo).

  Hay solo **dos roles**: en este equipo, coordinación y jefatura son las mismas personas (decisión del 19-11-2026).

  El rol se verifica **en el servidor** en cada ruta.

---

## 6. Pantallas

### 6.1 Funcionario (celular primero)
- **Ingreso / activación:** RUT, PIN o código, y la casilla "computador compartido".
- **Calendario** (pantalla principal):
  - mes con navegación;
  - SegmentedControl "Mi equipo · Todos · Turnantes" (por defecto, el propio);
  - cada día hábil muestra el estado de **cada bloque** con una palabra ("Libre", "1 de 2", "Lleno") y las pendientes se distinguen;
  - al tocar un día se ve quién falta (nombre, tipo según su etiqueta pública y estado). Nunca RUT, calidad, teléfono ni justificaciones.
- **"Pedir días"** (acción principal, siempre visible):
  - tipo, fechas, fracción;
  - si es turnante, sus turnos del rango con el reemplazo de cada uno;
  - si es CT, con quién cambia y la devolución;
  - comentario;
  - resumen en vivo: días hábiles, consumo por bloque, **saldo restante** y avisos de sobre cupo o saldo insuficiente;
  - confirmación con el código `AUS-aaaa-nnnn`.
- **Mis solicitudes:** solo las propias, con estado, motivo de rechazo y "Anular" (dentro del plazo).
- **Mis turnos:** próximos turnos propios y turnos que cubre.
- **Mi perfil:** saldos FL y ADM del año (asignado, ajustes, usados, pendientes, disponibles), cambiar PIN y sesiones abiertas.

### 6.2 Coordinación
1. **Bandeja:** pendientes ordenadas por fecha, con filtros (equipo, jornada, tipo, sobre cupo, vencidas), ocupación por bloque, saldo de la persona y turnos afectados. Autorizar (marcando extra o no) o rechazar (con motivo).
2. **Solicitudes:** búsqueda, revertir, anular y "registrar en nombre de".
3. **Calendario de ausencias** con todos los datos.
4. **Calendario de turnos:** por unidad y mes; crear, editar y cargar en lote; turnos sin cubrir; asignar reemplazo; "personal libre" de un día.
5. **Licencias:** registrar y anular.
6. **Horas extra:** por persona y mes, con exportación a CSV.
7. **Saldos:** por persona, ajustes con motivo y movimientos.
8. **Nómina:** CSV, edición, teléfono, rol (funcionario o coordinación), **códigos de activación o recuperación**, desbloquear y cerrar sesiones.
9. **Resumen y exportar:** resumen anual por persona y tipo; CSV de solicitudes (separador `;`, UTF-8 con BOM).
10. **Registro de cambios** (auditoría).
11. **Hoy:** ausentes de hoy, turnos sin cubrir y pendientes que vencen pronto.
12. **Configuración:** equipos y cupos, **tipos de ausentismo**, feriados y **respaldo** (`VACUUM INTO`).

---

## 7. API

`api.php?r=…`, siempre en JSON, con errores con código HTTP y mensaje en español. **Toda ruta exige sesión**, salvo `auth/ingresar`, `auth/activar` y `auth/sesion`. Las rutas `coord/*` exigen el rol coordinación. Toda escritura exige CSRF.

- **Autenticación:** `POST auth/activar`, `POST auth/ingresar`, `POST auth/salir`, `GET auth/sesion`, `POST auth/pin`, `GET/POST auth/sesiones` (listar y revocar).
- **Funcionario:** `GET config`, `GET calendario?mes=`, `GET turnos/mios`, `POST solicitudes/simular`, `POST solicitudes`, `GET solicitudes/mias`, `POST solicitudes/{id}/anular`, `GET saldos/mios`, `GET nomina/buscar?q=` (solo nombre y equipo, para elegir el reemplazo).
- **Coordinación:** solicitudes (listar, resolver, revertir, anular, en-nombre-de), calendario, turnos (CRUD, lote, asignar), licencias, horas-extra (+CSV), saldos (ver y ajustar), nómina (CRUD, importar, código, desbloquear, sesiones, rol), resumen y exportar, auditoría, hoy, equipos, tipos, feriados y respaldo.

Si se cambia alguna ruta, se documenta en el README.

---

## 8. Guía visual (basada en Vital)

### 8.1 Fuente y archivos
- Se usa el design system **Vital** sin modificar sus archivos: `tokens.css`, `components/bundle.css` y `fonts/` (Figtree variable, licencia OFL incluida). Van en `public/assets/vital/`.
- Los ajustes y componentes propios van en `public/assets/app.css`, usando **solo tokens de Vital**.
- **No** se carga `bundle.js` (React) ni Google Fonts. La CSP queda en `'self'`.

### 8.2 Tema
- **Claro por defecto y oscuro automático** según el dispositivo (`prefers-color-scheme`): en oscuro se aplica `data-theme="dark"` y Vital ya trae los valores.
- **Nunca** se escribe un color fijo; todo sale de los tokens. Sobre `brand`, el texto va en `on-brand`.

### 8.3 Color: tokens y uso

| Token | Claro | Uso en la app |
|---|---|---|
| `bg` | `#f4f7f6` | fondo de página |
| `surface` | `#ffffff` | tarjetas, diálogos, tablas y paneles |
| `surface-sunken` | `#eaf0ee` | campos, pista del SegmentedControl, días no hábiles |
| `surface-raised` | `#ffffff` | opción seleccionada y menús |
| `hairline` | `#dbe3e0` | divisores y bordes de 1 px (nunca texto) |
| `nav-glass` | `rgba(244,247,246,.78)` | barra superior en vidrio |
| `ink` / `ink-muted` | `#0f1d1a` / `#55655f` | texto principal / secundario |
| `brand` / `brand-strong` / `brand-soft` / `on-brand` | `#08766b` / `#08665d` / `#d7f0eb` / `#fff` | botón principal, enlaces, selección, borde del día de hoy |
| `accent` | `#b33f29` | como máximo una vez por pantalla ("Nuevo"). **Nunca** para estados |
| `success` / `-soft` | `#1e7448` / `#def2e6` | **Libre**, **Autorizada** |
| `warning` / `-soft` | `#8a5300` / `#fdf0d5` | **Cupo parcial** ("1 de 2"), **Sobre cupo**, **Saldo bajo** |
| `danger` / `-soft` | `#c0301e` / `#fde3df` | **Lleno**, **Rechazada**, errores, **Sin cubrir** |
| `info` / `-soft` | `#1f5fa6` / `#e0ebf8` | **Pendiente**, **Licencia**, avisos |
| neutral (`surface-sunken` + `ink-muted`) | — | **Anulada**, **Vencida**, **No hábil** |
| `focus` | `#1b5fc1` | contorno de foco de 2 px con 2 px de separación |

- Los **tintes** (`tint-*`) no se usan en esta versión.
- Todos los pares de texto superan 4,5:1 en ambos temas (medido en `docs/vital-evaluacion.md`).

### 8.4 Tipografía
- Figtree, con `-apple-system`, "Segoe UI" y system-ui de respaldo.
- **Escala reducida** (ajuste aprobado). No se usan `display` ni `title-1`.

  | Elemento | Escritorio | Celular |
  |---|---|---|
  | Título de página | `title-2` (40/44) | 28/34 (`title-3`) |
  | Sección o tarjeta | `title-3` | `headline` (21/28) |
  | Cuerpo | `body` 17/26 | igual |
  | Texto de tabla y descripciones | `callout` 15/22 | igual |
  | Metadatos, número y estado del día | `caption` 13/18 | igual |
  | Sobretítulos | `eyebrow` 12/16, en mayúsculas | igual |
  | Saldos y totales | `metric-sm` (28/32), cifras tabulares | igual |

- Las cifras van siempre con `tabular-nums`.

### 8.5 Espaciado, radios y profundidad
- **Espaciado:** escala de 4 px (`space-1` 4 a `space-9` 96).
  - márgenes laterales: 24 px en el celular y 64 px en escritorio;
  - contenedor de 1120 px;
  - entre bloques, `space-7`;
  - entre título y contenido, `space-5`;
  - entre tarjetas, `space-5`.
- **Radios:** `radius-sm` 8 (badges cuadrados), `radius-md` 14 (campos y celdas del calendario), `radius-lg` 20 (tarjetas e indicadores), `radius-xl` 28 (diálogos) y píldora (botones, badges y controles segmentados).
- **Profundidad:** `shadow-card` en tarjetas blancas y `shadow-raised` en diálogos y menús. Sin bordes laterales de color ni degradados.
- **Movimiento:** 150 a 300 ms con `cubic-bezier(.2,.7,.2,1)`, desactivado con `prefers-reduced-motion`.

### 8.6 Componentes de Vital usados (con ajustes)

| Componente | Uso | Ajuste |
|---|---|---|
| **NavBar** (`vt-nav`) | Fija, en vidrio y de 52 px: "Ausentismos · Kinesiología HPM" en texto, enlaces "Calendario · Mis solicitudes · Mis turnos" y, en coordinación, sus secciones. A la derecha, **"Pedir días"**. | Enlaces con área táctil de **44 px** o más. En el celular (< 768 px), los enlaces pasan a una barra inferior de pestañas de texto o a un menú, y "Pedir días" queda fijo abajo como botón `lg`. |
| **Button** (`vt-btn`) | `primary` (una por vista): "Pedir días", "Enviar solicitud", "Autorizar". `secondary`: acciones alternativas. `danger`: "Anular", "Rechazar". `plain`: "Ver detalle ›". | **Alto mínimo de 44 px** en todos los tamaños (`sm` ajustado). Textos con verbo: "Pedir días", "Anular solicitud". |
| **Badge** (`vt-badge`) | Estados de solicitud y de turno, siempre con palabra: "Pendiente", "Autorizada", "Rechazada", "Anulada", "Sobre cupo", "Licencia", "Sin cubrir", "Extra". | Tonos fijos según la §8.3. |
| **Card** (`vt-card--surface`) | Solicitud, detalle del día, saldo y tarjetas de la bandeja. | Tamaño `md` en listas. |
| **MetricCard** (`vt-metric--compact`) | "FL disponibles 9 días", "ADM disponibles 4,5 días" y horas extra del mes. | Sin sparkline. El estado ("Saldo bajo") va con su badge. |
| **TextField** (`vt-field`) | RUT, comentario, justificación, motivo y búsqueda. | Errores que dicen cómo corregir: "Revisa el dígito verificador del RUT." |
| **SegmentedControl** (`vt-seg`) | "Mi equipo · Todos · Turnantes", "Día completo · Mañana · Tarde", "Largo · Noche". | Opciones con **44 px** de alto. |

### 8.7 Componentes propios (con tokens de Vital)
- **Calendario mensual** (`ap-cal`):
  - cuadrícula de 7 columnas con celdas en `surface` y `radius-md`;
  - número del día en `caption` 600;
  - **dos franjas** (AM y PM) con un color de estado de fondo `*-soft` y su palabra ("Libre", "1 de 2", "Lleno");
  - en el celular, la palabra se abrevia sin perder sentido ("1/2" acompañado de un `aria-label` completo);
  - pendientes: borde punteado de 1,5 px en el color del estado;
  - día de hoy: contorno `brand` de 2 px;
  - no hábil: `surface-sunken` con `ink-muted`;
  - seleccionado: `shadow-raised`;
  - cada celda tiene un `aria-label` tipo "martes 9 de diciembre: mañana 1 de 2, tarde libre";
  - los **controles del mes** (‹ mes ›, Hoy, vista) van en **una sola fila** en el celular.
- **Calendario de turnos** (`ap-turnos`): filas por día y columnas "Largo" y "Noche" (en el celular, lista por día). Cada turno es una tarjeta pequeña con el nombre del titular o de quien cubre, más los badges "Extra" o "Sin cubrir".
- **Tabla** (`ap-tabla`): `surface` con `shadow-card` y `radius-lg`, encabezado en `eyebrow` `ink-muted`, filas de 1 px en `hairline` (sin cebra), celdas en `callout`. En el celular se convierte en tarjetas.
- **Select y autocompletado:** con el mismo aspecto que TextField (`surface-sunken`, `radius-md`), y la lista en `surface` con `shadow-raised`.
- **Checkbox y radio:** nativos con `accent-color: var(--brand)`, de 20 px, dentro de una etiqueta de 44 px o más.
- **Diálogo** (`dialog`): `surface`, `radius-xl`, `shadow-raised`, fondo semitransparente de `ink` al 40 %.
- **Aviso** (`ap-aviso`): fondo `*-soft`, texto en el tono del estado, ícono y palabra, `radius-lg`. Sin borde lateral.
- **Toast:** `ink` sobre `surface` con `shadow-raised`, en píldora y abajo al centro.
- **PIN:** un campo numérico (`inputmode="numeric"`, `autocomplete="current-password"`) que se ve como 6 casillas, con letra `metric-sm`.
- **Íconos:** Lucide (licencia ISC), copiados en el proyecto como SVG de trazo de 1,75 px, en `currentColor`. Si un botón solo tiene ícono, lleva `aria-label`.

### 8.8 Voz y contenido
- Trato de **tú**, verbo primero en los botones, titulares en tipo oración y sin emojis.
- Sin signos de exclamación, salvo en "¡Listo!" al enviar. Nunca alarmar: "Lleno", "Revisar", "Sin cubrir".
- Números con coma decimal ("4,5 días"). Fechas como "jueves 21 nov".

### 8.9 Accesibilidad
- Objetivos táctiles de 44 × 44 px o más, navegación completa por teclado y foco visible siempre.
- Etiquetas de formulario visibles, con `aria-invalid` y el mensaje asociado en los errores.
- Estados que se entienden en blanco y negro, contraste de 4,5:1 o más, y el calendario usable con lector de pantalla.

### 8.10 Impresión

Calendario del mes en tema claro: sin barra, sin sombras, con bordes `hairline` en las celdas y las palabras de estado visibles.

---

## 9. Verificación (obligatoria antes de dar por terminado)

1. **Tests automatizados** (`php tests/run.php`, sin dependencias):
   - **Todos los de `SPEC.MD.md` §9**, adaptados a las reglas nuevas, **incluida la concurrencia**: dos procesos piden el último cupo de un bloque a la vez y solo uno lo obtiene sin sobre cupo.
   - **Cupo por bloque:** con cupo 2, el 3.º AM queda sin cupo; AM + PM de dos personas dejan cada bloque en 1; un día completo ocupa ambos bloques; un FL ≥ 5 de titular consume 0; un FL de honorarios consume 1.
   - **Saldos:**
     - FL de lunes a domingo descuenta 5;
     - ADM ½ descuenta 0,5;
     - una pendiente reserva saldo;
     - con saldo insuficiente se bloquea, y dos solicitudes simultáneas no superan el saldo;
     - autorizar → rechazar → autorizar no descuenta dos veces;
     - anular devuelve el saldo;
     - un ajuste con motivo suma;
     - la asignación anual de FL 15 y ADM 6 no se duplica al repetirla;
     - un FL que cruza de año descuenta de cada año lo suyo.
   - **Turnos:**
     - un turnante sin turno cargado no puede pedir;
     - autorizar marca `cubierto_por` y `es_extra`;
     - si algo falla, la transacción se revierte entera (sin estados a medias);
     - anular restaura solo los turnos de esa solicitud;
     - un CT autorizado intercambia los titulares, con y sin devolución;
     - una licencia deja los turnos sin cubrir.
   - **Horas extra:** cada fila de la tabla de §4.7, más la corrección manual y el medio día.
   - **Login:**
     - la activación con un código válido, vencido o ya usado;
     - PIN trivial rechazado;
     - 5 errores bloquean la cuenta y no la IP;
     - una sesión de 180 días se renueva con el uso;
     - una sesión compartida vence a los 30 minutos;
     - una sesión revocada no sirve;
     - un código nuevo invalida el PIN anterior;
     - sin sesión, toda ruta protegida devuelve 401;
     - un funcionario no accede a `coord/*` (403);
     - CSRF obligatorio;
     - un funcionario no puede anular la solicitud de otro.
   - **Privacidad:** `nomina/buscar` y el calendario nunca devuelven RUT, calidad, teléfono ni justificaciones; la simulación no expone datos de otras personas.
   - **Un test que reproduce cada bug corregido**, escrito antes de la corrección.
2. **Carga inicial:** el script corre dos veces sin duplicar, `--dry-run` no escribe nada y el CSV de origen queda intacto.
3. **Interfaz** a 375 px y en escritorio, en tema claro y oscuro: calendario en los tres estados de cupo por bloque, formulario de turnante, ingreso con PIN.
4. **En DreamHost:** `config.php`, el `.sqlite`, `lib/` e `install.php` no son accesibles desde el navegador.

---

## 10. Estructura

```
public/                 → web en DreamHost
  index.html            app (funcionario o coordinación según rol)
  assets/vital/         tokens.css, bundle.css, fonts/, licenses/  (sin modificar)
  assets/app.css        ajustes y componentes propios con tokens de Vital
  assets/js/            módulos: comun, ingreso, calendario, pedir, mis, turnos, coord, config
  assets/iconos/        SVG de Lucide utilizados
  api.php, app-dir.php, manifest.webmanifest, .htaccess
lib/                    PRIVADO: db, migraciones, auth, reglas (cupo, saldos, turnos, horas extra), handlers
config.example.php      → config.php (PRIVADO)
herramientas/           carga-inicial.php (nómina, saldos y códigos), con --dry-run
tests/                  run.php, worker.php, casos por módulo
.github/workflows/      deploy.yml (SFTP, excluye config y datos)
docs/                   análisis, propuesta, evaluación de Vital, plan
```

---

## 11. Preparado para después

- Correo: `notificar()` se llama al crear, resolver y anular.
- Ingreso con huella o rostro (passkeys/WebAuthn) como segundo método.
- Bloqueo de días o cupos distintos por fecha.
- Anticipación mínima y horizonte máximo (en `config.php`, desactivados).
- Tintes de Vital por equipo.
- Feriado legal según antigüedad (hoy se resuelve con un ajuste manual).

---

## 12. Decisiones

### 12.0 Tomadas por ti (19-11-2026)

| Tema | Decisión |
|---|---|
| Login | Opción A: RUT + PIN de 6 dígitos, código de activación y recuperación entregado uno a uno por coordinación, 180 días en el celular, modo compartido. |
| Arquitectura | Camino 2: PHP + SQLite + JavaScript sin compilar, solo DreamHost; se porta lo de la app anterior y se descarta Firebase. |
| Alcance | Ausentismos + calendario de turnos + turnos y horas extra (ver y exportar). |
| Medio día | Cupo por bloque AM/PM. |
| Equipos | 19. |
| Saldos | FL y ADM, regla fija de 15 y 6, ajustes puntuales, año calendario sin arrastre, **bloquear** si no alcanza, FL de turnante por día hábil. |
| Tipos | Licencias registradas por coordinación (se ven como "Licencia", no consumen cupo). Coordinación crea tipos y configura cupo, saldo, medio día, quién lo pide, reemplazo y visibilidad. |
| Turnantes | Se exige turno cargado. |
| Cambio de turno | Coordinado de antemano por el solicitante; sin aceptación de la contraparte en la app. |
| Roles | Dos roles: funcionario y coordinación (en este equipo, coordinación y jefatura son lo mismo). Coordinación ve todos los equipos y también configura. |
| Vistas sin login | Ninguna. |
| Teléfono | Se guarda, visible solo para coordinación. |
| Visual | Vital, encabezado en vidrio, tema oscuro automático, sin tintes, con los ajustes de 44 px y escala reducida. |
| Firebase | No se toca. |

### 12.1 a 12.7 Interpretaciones mías [a confirmar]

1. **FL de turnante:** se pide por rango, el sistema lista sus turnos del rango, se exige al menos uno, se indica un reemplazo por turno y el saldo descuenta los días hábiles del rango.
2. **ADM de turnante:** un turno completo descuenta 1 ADM; una mitad del turno, 0,5.
3. **Diurnos en el calendario de turnos:** no tienen turnos propios. Aparecen solo cuando cubren uno. Para pedir días no necesitan turno cargado.
4. **"Extra" al autorizar:** por defecto, sí, para el reemplazo; coordinación puede desmarcarlo.
5. **Licencia:** nace autorizada, sin pasar por la bandeja.
6. **Las pendientes reservan saldo**, para que dos pendientes no superen juntas el saldo.
7. **Ingreso a mitad de año:** recibe 15 y 6 completos, y coordinación ajusta con motivo si corresponde.
