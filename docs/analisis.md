# Fase 1 — Análisis de las dos versiones

Fecha: 19-11-2026 · Rama: `unificacion/fase1-analisis` · Solo lectura y ejecución local. No se escribió código de producto.

## 0. Qué es cada cosa en este repositorio

El repo no tiene la estructura `app-anterior/` + `app-nueva/` que menciona el encargo. Esto es lo que hay:

| Nombre en el encargo | Dónde está | Qué es |
|---|---|---|
| **App anterior** | `sistema-hospital-main.zip` (sin versionar) | React 19 + TypeScript + Vite + **Firebase** (Auth + Firestore). Se llama "Gestor de Turnos Hospitalarios (v0.4.9.3)" / "HospitalSys". |
| **SPEC** | `SPEC.MD.md` (sin versionar) | El diseño de 12 secciones. |
| **App nueva** | La raíz del repo (`public/`, `lib/`, `tests/`…), commits `c217e46` y `0abae59` | Implementación de `SPEC.MD.md` en PHP 8 + SQLite. |
| Paquete de subida | `ausentismos-subir.zip` (ignorado por git) | La app nueva empaquetada para DreamHost (`privado/` + `web/`). No trae `config.php`. |

**Cosas que faltan o no calzan con el encargo:**
- **`docs/referencia-estilo.png` no existe.** Comparé contra la descripción escrita del encargo (encabezado blanco, "Pedir días" en píldora verde azulado y pestañas de texto). Esa descripción corresponde exactamente a la app nueva (ver la captura `docs/capturas/nueva-calendario-375.jpg`).
- Los campos `[COMPLETAR]` (tecnología, si hay datos reales, bugs conocidos) llegaron vacíos. Los respondí desde el código donde pude, y lo que no pude va como pregunta en la §6 y al final.

**Cómo ejecuté cada una sin tocar datos reales:**
- **App nueva:** `php tests/run.php` (60 verificaciones, todas OK) y el servidor local `php -S localhost:8080 -t public` sobre `dev-data/ausentismos.sqlite`, que está vacía: 19 equipos y 0 funcionarios, 0 solicitudes. Las reproducciones usaron bases temporales en `%TEMP%`.
- **App anterior:** su configuración de Firebase de producción (`administrador-de-turnos-hpm`) está escrita en `lib/firebase.ts`, así que **no la ejecuté contra Firebase**. La descomprimí en una carpeta temporal y la levanté con Vite, reemplazando `firebase/app`, `firebase/auth` y `firebase/firestore` por simuladores en memoria con datos ficticios. Verifiqué que no hubo ninguna petición a dominios de Google. Las reglas de Firestore las analicé leyendo `firestore.rules`; no las probé contra el proyecto real.

---

## 1. Cómo funciona cada una

### 1.1 App anterior (React + Firebase)

**Tecnología y estructura.** SPA en React 19 + TypeScript, compilada con Vite 6. Tailwind entra por CDN (`cdn.tailwindcss.com`, el modo de desarrollo, no recomendado para producción), y jsPDF por CDN. Unos 14 300 líneas, con componentes muy grandes: `ProfilesView.tsx` tiene 2005 líneas y `App.tsx` 1509. No tiene tests. Además tiene un error de compilación TypeScript: `AbsenceReason.UNPAID_LEAVE` no existe (`App.tsx:872`). Vite compila igual porque no verifica tipos.

**Ejecución y despliegue.** `npm run dev` en local. En producción, GitHub Actions (`.github/workflows/deploy.yml`) hace `npm install && npm run build` y sube `dist/` por SCP a DreamHost en cada push a `main`. DreamHost solo sirve archivos estáticos; **todo el backend es Firebase** (Google Cloud).

**Datos.** Firestore, en las colecciones `users`, `services`, `shifts`, `shiftRequests`, `notifications` y `auditLog`. El cliente tiene un caché persistente (IndexedDB) y además copia todas las solicitudes en `localStorage['hospital_shift_requests']` de cada navegador.

**Modelo de datos (`types.ts`):**
- `User`: nombre, rol (`Administrador` | `Usuario`), servicio principal, RUT (texto libre, sin validar), edad, email, teléfono, foto, `isDiurnalWorker`, `isStandBy` y `profileData`. Ese último guarda **saldos** (`vacations`, `adminDays`, `unpaidLeave`, `unjustifiedAbsences`), **licencias médicas**, solicitudes, ausencias y reemplazos.
- `Service`: nombre y color. No tiene cupo.
- `Shift`: un turno concreto (`Día` | `Noche`) de una persona en una fecha y servicio, con banderas de turno extra y horas extra.
- `ShiftRequest`: solicitante, tipo (`shift_change` | `absence` | `vacation`), `permissionType` en texto libre, fechas, quién cubre (una persona o una por turno), si es extra, medio día AM/PM, devolución de cambio, motivo y estado (`pending` | `approved` | `rejected`).
- **Una misma solicitud puede vivir en tres lugares:** `shiftRequests`, `users.profileData.permissionRequests` y `users.profileData.replacementAbsences`. `App.tsx:540-631` las fusiona en la pantalla.

**Flujo del funcionario.** Hay dos caminos:
- **(a) Sin cuenta**, con un enlace `?solicitud=true`, `?public=true` o `?resumen=true`. Elige su nombre en una lista desplegable que muestra el RUT de todos, elige el trámite (cambio de turno, ausencia/permiso o vacaciones), las fechas, quién lo cubre (una persona o una por turno, tomado de la lista de turnos ya cargados), un motivo obligatorio y una casilla de "declaro veracidad". Después consulta el estado en "Estado Solicitudes", que muestra las solicitudes de todos.
- **(b) Con cuenta**: entra con correo y contraseña y ve Calendario, Nueva Solicitud, Mis Turnos, Mis Solicitudes (con "Cancelar", que borra la solicitud) y Mi Perfil (con sus saldos).

**Flujo de coordinación** (rol Administrador): Panel de Control, Resumen de Solicitudes (aprobar o rechazar con nota opcional, "Revertir a Pendiente", eliminar), Perfiles (ficha completa con saldos editables y licencias), Turnos Extras (cálculo de horas), calendario de turnos por servicio (asignar, intercambiar, reasignar, crear turnos en lote) y Registro de Cambios. **Al aprobar**, la app descuenta el saldo, reasigna los turnos del calendario al reemplazante y crea notificaciones.

**Login (pregunta específica del encargo):**

| Aspecto | Cómo funciona | Evaluación |
|---|---|---|
| Registro | `AuthView.tsx` tiene un formulario de registro con un **selector de rol (Usuario / Administrador)**, pero ningún botón lo muestra (`setIsLogin` nunca pasa a `false`). Las cuentas las crea un administrador en `UserModal.tsx` con correo y una **contraseña que elige el administrador**. | El selector de rol es código muerto en la interfaz, pero la API de registro de Firebase sigue abierta (ver Seguridad). |
| Contraseña | Firebase Auth, mínimo 6 caracteres (el mínimo de Firebase). | Débil. Además, el administrador conoce las contraseñas iniciales. |
| Verificación | Exige verificar el correo antes de entrar (`App.tsx:270`, `VerificationNeededView`). | Fricción: la persona necesita acceso a su correo la primera vez. |
| Recuperación | **No existe.** No se usa `sendPasswordResetEmail` en ninguna parte. | Si alguien olvida su clave, depende de que un administrador le cree otra cuenta. |
| Roles | `Administrador` y `Usuario`, guardados en `users/{uid}.role`. | **Solo se aplican en la interfaz.** Las reglas de Firestore no los revisan. |
| Duración de la sesión | Firebase guarda la sesión de forma persistente: queda abierta en el dispositivo hasta cerrar sesión. | Cómodo en el celular. Arriesgado en computadores compartidos del hospital. |
| Seguridad general | 🔴 Ver la §4 (bugs B1 a B3). | Insegura en su estado actual. |

### 1.2 App nueva (PHP + SQLite, según `SPEC.MD.md`)

**Tecnología y estructura.** PHP 8.1+ sin frameworks ni Composer, SQLite por PDO con WAL, `foreign_keys` y `busy_timeout`. El frontend es HTML, CSS y JavaScript sin paso de compilación (`public/assets/app.js`, `coord.js`, `common.js`). Unas 3600 líneas. Incluye la batería de tests `tests/run.php` (más `tests/worker.php` para la prueba de concurrencia).

**Ejecución y despliegue.** En local, `php install.php` y luego `php -S localhost:8080 -t public` (o `iniciar-local.bat`). En DreamHost se sube por SFTP: `public/` va a la carpeta web, y `lib/` con `config.php` a una carpeta privada, enlazada desde `app-dir.php`. La base vive fuera de la carpeta web. `.htaccess` fuerza HTTPS y bloquea `*.sqlite`, `config.php`, `lib/` y archivos `.csv`/`.md`. No hay despliegue automático.

**Modelo de datos** (`lib/db.php`): tablas `equipos` (cupo diurno), `funcionarios` (RUT único validado, jornada, equipo, calidad), `feriados`, `solicitudes` (con una copia de los datos de la persona al momento de pedir, IP y user agent), `solicitud_dias` (una fila por día con su consumo de cupo), `auditoria` y `rate` (límite de intentos).

**Flujo del funcionario** (sin cuenta):
1. Calendario mensual por equipo, por "todos los equipos" o por turnantes.
2. "Pedir días": escribe su nombre con autocompletado (sin RUT), y se muestran su jornada, equipo y calidad.
3. Elige tipo (FL / ADM / CT), fechas y fracción. Si es turnante, indica turno, unidad y reemplazo por cada fecha.
4. Ve un resumen en vivo del cupo, que se calcula en el servidor (`solicitudes/simular`).
5. Si no hay cupo, puede enviarla "sobre cupo" con justificación.
6. Recibe un código `AUS-2026-0001`.
7. En "Mis solicitudes" busca por nombre o código, y puede anular dando su RUT hasta las 23:59 del día anterior.

**Flujo de coordinación** (`/coordinacion/`): bandeja de pendientes con filtros y ocupación, todas las solicitudes (revertir, anular, vincular a la nómina), calendario completo, resumen por persona, nómina (importar CSV con upsert por RUT), equipos y cupos, feriados, exportación CSV (separador `;` con BOM) y respaldo `.sqlite` (con `VACUUM INTO`). Pide un campo "Resuelto por" en texto libre.

**Acceso de coordinación:** una clave compartida con `password_hash`, sesión de 8 horas por inactividad, CSRF y bloqueo tras 5 intentos por IP. **Pero `config.example.php` trae `coord_sin_clave => true`**, y con eso `/coordinacion/` queda abierta para cualquiera que tenga el enlace (ver la §4, bug N1).

---

## 2. Comparación lado a lado

Leyenda: **A** = app anterior · **N** = app nueva · **SPEC** cuando el punto es del diseño y no del código.

| Capacidad | App anterior | App nueva | Mejor | Por qué |
|---|---|---|---|---|
| **Identidad y login** | Correo y contraseña con Firebase. Cuentas creadas por el administrador, verificación por correo y sesión persistente. Además, un portal público sin cuenta. Sin recuperación de clave. | Funcionarios sin cuenta: eligen su nombre y anulan con su RUT. Coordinación con clave compartida, que viene **desactivada por defecto**. | **Ninguna** | A tiene login, pero cualquiera puede saltárselo (roles sin validar en el servidor, portal público con escritura). N no identifica a nadie. Se decide en la Fase 2. |
| **Roles** | Administrador / Usuario, aplicados solo en la interfaz. | Público / coordinación (clave compartida, sin persona). | Empate (ambos insuficientes) | A tiene roles por persona, pero no los hace cumplir. N sí hace cumplir el suyo (cuando está activado), pero no sabe quién resolvió: "Resuelto por" es texto libre. |
| **Nómina** | Usuarios con nombre, RUT sin validar, edad, correo, teléfono, foto, servicio y bandera `isDiurnalWorker`. Sin calidad jurídica ni jornada explícita. Se carga uno por uno. | Nombre, RUT validado y único, jornada, equipo y calidad. Importación CSV con resumen de errores, edición y desactivación. | **N** | Tiene los campos que exigen las reglas §4 (calidad, jornada) y la carga masiva. A guarda datos que no hacen falta (edad, foto) y le falta la calidad. |
| **Tipos de ausentismo** | Cambio de turno, Permiso administrativo (con ½ día AM/PM), Feriado legal / Vacaciones, más texto libre que incluye "Licencia", "Sin goce" e "Injustificada". El tipo se deduce **buscando palabras dentro del texto**. | FL, ADM (completo, AM/PM, o mitades de turno) y CT, como valores cerrados. | **N** | Tipos cerrados y validados en el servidor. A clasifica con `includes('vacacion')`, lo que es frágil, y mezcla licencias médicas, que SPEC excluye. |
| **Cálculo de cupo** | **No existe.** No hay cupo por equipo ni aviso de día lleno. | Cupo por equipo y día, solo para diurnos: ADM 1 o 0,5; FL < 5 hábiles = 1 por día; FL ≥ 5 hábiles de titular o contrata = 0; honorarios = 1. Se verifica en el servidor dentro de `BEGIN IMMEDIATE`, y el test de concurrencia con 2 procesos pasa. | **N** | Es la regla central del proyecto y solo N la tiene. Es segura ante dos solicitudes simultáneas (probado). Un punto débil del SPEC está en la §4.4. |
| **Turnantes y reemplazos** | Muy completo: turnos reales cargados por servicio, y al pedir se ven los turnos de la persona en el rango, con un reemplazante por turno elegido de la lista y marca de turno extra. Al aprobar, la app **reasigna el turno en el calendario**. | Por fecha: turno, unidad y reemplazo en **texto libre** (con sugerencias). No sabe qué turnos tiene cada persona; el funcionario los declara. | **A** en funcionalidad · **N** en simplicidad | A aprovecha el calendario de turnos que ya existe, pero exige mantenerlo al día. N sigue el SPEC ("el sistema no valida contra un calendario de turnos"). Es una decisión tuya (ver la §5). |
| **Cambio de turno** | Con quién, devolución opcional. Al aprobar, **intercambia los turnos en el calendario**. | Con quién (obligatorio), devolución opcional. Solo queda registrado. | **A** si se mantiene el calendario de turnos, **N** si no | Igual que la fila anterior. Ojo: en N, la fecha de devolución no entra a `solicitud_dias`, así que no aparece en el calendario. |
| **Flujo de autorización** | Aprobar con nota opcional; rechazar con nota **opcional**; "Revertir a pendiente"; eliminar. Sin transacciones. **Revertir y volver a aprobar descuenta el saldo dos veces** (ver B5). | Pendiente → autorizada o rechazada (con motivo obligatorio); revertir autorizada ↔ rechazada re-verificando el cupo; anulación; "vencidas sin resolver". Todo con auditoría y en transacción. | **N** | Estados claros, motivo obligatorio, reversión que vuelve a verificar el cupo, y probado. |
| **Calendario** | Calendario de **turnos** por servicio (quién trabaja de día o de noche), con feriados **fijos en el código** hasta 2035 (duplicados en dos archivos) y un buscador de "personal libre". En el celular se ve denso y la barra de pestañas se sale de la pantalla a 375 px. | Calendario de **ausencias y cupo**: Libre / 1 de 2 / Lleno con texto (no solo color), pendientes con borde punteado, vista de turnantes, `aria-label` por día y versión para imprimir. Feriados editables. | **N** para ausentismos | Responde a la pregunta del funcionario ("¿hay cupo ese día?"). A responde otra pregunta ("¿quién está de turno?"). |
| **Mis solicitudes y anulación** | Con cuenta: lista propia y "Cancelar" **solo si está pendiente**, lo que **borra** la solicitud (sin estado anulada). En público: lista de **todas** las solicitudes de todos, con motivo. | Búsqueda por nombre o código. Anulación de pendientes o autorizadas hasta las 23:59 del día anterior, con RUT. Estado "anulada" con auditoría. | **N** | Conserva la historia y tiene plazo. Con login, la búsqueda por nombre y la anulación con RUT cambian (ver la Fase 2). |
| **Vista de coordinación** | Panel muy completo: dashboard del día, turnos sin cubrir, perfiles con saldos, horas extra, registro de cambios. | Bandeja con filtros (sobre cupo, fuera de nómina, vencidas), ocupación del equipo por fila, nómina, equipos, feriados. | **A** en amplitud · **N** en lo que pide el SPEC | A cubre mucho más que ausentismos (es un gestor de turnos). N cubre exactamente §5.2. |
| **Resumen y exportación** | PDF del calendario de turnos por servicio (marca como "(Licencia)" cualquier permiso aprobado). Sin CSV. | Resumen anual por persona y tipo, en días con 0,5 (informativo). CSV de solicitudes y del resumen, listo para Excel en español. | **N** | Exportación útil para planillas y sin etiquetas engañosas. |
| **Diseño y uso en celular** | Tailwind índigo, barra lateral, densidad alta, textos de 10 a 12 px, emojis como íconos. En el portal público a 375 px las pestañas se salen de la pantalla. Formulario largo (con casilla obligatoria de "veracidad" y motivo obligatorio). | Una columna, botones de 44 px o más, "Pedir días" siempre visible, formulario de un paso. A 375 px los controles del mes ocupan media pantalla antes del calendario. | **N** | Cumple "pedir en menos de un minuto". A pide más campos y más pasos. |
| **Seguridad** | 🔴 Reglas de Firestore: `shiftRequests`, `auditLog` y `notifications` con lectura **y escritura públicas**; `users` con lectura pública (RUT, correo, teléfono, licencias) y escritura para cualquier usuario autenticado, que puede volverse administrador. Errores ocultos. Sin CSP. | Consultas preparadas, `textContent` (sin `innerHTML` con datos), CSP, cabeceras, honeypot, límite por IP, CSRF y `.htaccess`. **Pero** la coordinación viene sin clave por defecto, y hay fugas de calidad jurídica y RUT→nombre (ver la §4). | **N** | Sus fallas son de configuración y de detalle, y se corrigen. Las de A son estructurales. |
| **Respaldos** | Ninguno en la app. Firestore no se puede exportar sin activar el plan pago (Blaze) más `gcloud`. | Botón "Descargar respaldo (.sqlite)" con `VACUUM INTO`, más instrucciones de `sqlite3 .backup`. Sin respaldo automático. | **N** | Hay un camino documentado y probado. Falta un respaldo programado. |
| **Hosting** | DreamHost (estáticos) más Firebase (datos en Google Cloud, plan desconocido). Dos proveedores y dos paneles. Despliegue automático por GitHub Actions. | Solo DreamHost: PHP + SQLite en el hosting compartido que ya pagas. Subida manual por SFTP. | **N** | Un solo proveedor, los datos quedan en tu hosting y sin costo extra. Del anterior conviene rescatar el **despliegue automático**. |

---

## 3. Estilo visual actual

### 3.1 App nueva (`public/assets/app.css`), que coincide con la descripción del estilo final

El comentario del CSS todavía dice "Sistema Gerson 1 — tema crema", pero los valores ya fueron reemplazados por una paleta blanca y verde azulada. Quedan **restos de Gerson 1**: las filas alternas de las tablas usan `rgba(246,235,207,.45)` (crema), el fondo del diálogo usa `rgba(43,58,57,.55)` (petróleo Gerson) e `install.php` usa crema (`#fdf6e3`, `#416b66`). La impresión también redefine `--fondo-alt` y `--linea` con valores de Gerson.

**Colores** (contraste medido con la fórmula WCAG):

| Token | Valor | Uso | Contraste |
|---|---|---|---|
| `--fondo` | `#ffffff` | fondo general y encabezado | — |
| `--fondo-alt` | `#f3f6f7` | tarjetas, celdas del calendario, ficha de lectura | — |
| `--tinta` | `#1c2b31` | texto principal | — |
| `--tinta-suave` | `#54646b` | notas, etiquetas, metadatos | 6,15 sobre blanco · 5,67 sobre `fondo-alt` |
| `--linea` | `#dfe6e9` | bordes y separadores | — |
| `--petroleo` | `#12313a` | título del encabezado, hover del botón principal, toast | 13,76 |
| `--marca` | `#0e6b7c` | **botón "Pedir días"**, pestaña activa, títulos, foco de campos | 6,15 (texto blanco encima) |
| `--marca-vivo` | `#2bb3c9` | definido pero **no se usa** | — |
| `--acento` | `#d35e36` | contorno del día de hoy y del foco | 3,87 (válido para contornos, no para texto) |
| `--acento-texto` | `#a34427` | definido, no se usa | — |
| `--arena` | `#d9eef2` | sombra de foco y sugerencia seleccionada (ya no es arena: es celeste) | — |
| `--normal` / `-fondo` | `#1e6b52` / `#ddf1e9` | **Libre** y autorizada | 5,44 |
| `--precaucion` / `-fondo` | `#7a5200` / `#fbefd0` | **Cupo parcial** ("1 de 2") y sobre cupo | 6,05 |
| `--critico` / `-fondo` | `#9b1c31` / `#f9e1e4` | **Lleno**, rechazada y errores | 6,50 |
| `--info` / `-fondo` | `#285e6e` / `#dcebf0` | **Pendiente**, avisos y días de turnantes | 5,89 |
| no hábil | `#5c676c` / `#eef1f2` (sin token) | fin de semana y feriado | 5,12 |

**Tipografías:** títulos en `Outfit` 600 (con respaldo Avenir Next / Segoe UI / system-ui); texto en `DM Sans` 400/500/700, con cifras tabulares en toda la página. Se cargan desde Google Fonts. **Escala:** h1 28 px (el título del encabezado, 22 px), h2 20 px, h3 16 px, cuerpo 16 px, `.nota`/`.ayuda` 13 px, `.etiqueta` 12 px en mayúsculas con espaciado `.08em`, estado del día 11 px, subtítulo del día 10 px (pequeño para el celular).

**Espaciados:** escala de 4 px (`--e1` 4 · `--e2` 8 · `--e3` 16 · `--e4` 24 · `--e5` 40 · `--e6` 64). Ancho máximo de 1100 px. Separación de 4 px entre días.

**Radios:** 10 px en tarjetas, campos, sugerencias y avisos; 12 px en celdas del día; 999 px (píldora) en botones, pestañas, opciones y chips; 24 px en diálogos. Sin sombras: la jerarquía la dan el color y el espacio.

**Componentes:**
- **Encabezado** (`.barra`): blanco con una línea inferior de 1 px. El título "Ausentismos · Kinesiología HPM" en `--petroleo` a 22 px, y a la derecha el botón `btn-primario btn-grande` "Pedir días" (píldora de 52 px de alto, letra de 18 px, fondo `--marca`, texto blanco). Logo opcional de 36 px.
- **Pestañas** (`.nav`, `.tabs`): enlaces de texto de 44 px de alto en peso 500. La activa es una píldora rellena de `--marca` con texto blanco (no un subrayado).
- **Botones:** `.btn` es una píldora con borde de 2 px en `--marca` y texto `--marca`; `.btn-primario` va relleno; `.btn-peligro` usa `--critico`; `.btn-chico` mide 36 px de alto.
- **Tarjetas:** `.tarjeta` con fondo `--fondo-alt` y borde de 1 px; `.tarjeta-blanca` y `.sol` en blanco con borde.
- **Formularios:** etiqueta en negrita sobre el campo, campos de 44 px con borde de 2 px en `--linea` (en `--marca` con un halo celeste al enfocarlos). Las opciones (radio) se ven como píldoras que se rellenan al marcarlas. Los avisos tienen un borde izquierdo de 6 px.
- **Calendario:** cuadrícula de 7 columnas; cada celda mide al menos 68 px de alto, con el número en Outfit de 15 px y el estado en texto ("Libre", "1 de 2", "Lleno"). El día de hoy lleva un contorno `--acento` de 3 px y el día seleccionado un borde interior de 3 px en `--tinta`.
- **Estados:** libre = verde `normal`; **pendiente = borde punteado** del color del estado más la palabra "pendiente"; autorizada = chip `normal`; rechazada = chip `critico`; lleno = rojo `critico`; no hábil = gris. Los chips son píldoras de 24 px de alto con letra de 13 px en negrita.

### 3.2 App anterior (Tailwind por CDN, sin tokens)

`index.css` define variables de un **tema oscuro** (`--background-color #111827` y otras) que **no se usan**. Los colores reales salen de clases de Tailwind (conteo de usos en `App.tsx` y `components/`):

| Uso | Valor (Tailwind) | Notas |
|---|---|---|
| Marca / principal | `indigo-600` `#4f46e5` (46), hover `indigo-700`, fondos `indigo-50/100` | Botones, pestaña activa, día feriado (celda completa en índigo), día de hoy. |
| Texto | `gray-700/500/800/900`; mucho `gray-400` `#9ca3af` (84 usos) | `gray-400` sobre blanco da **2,54:1**, bajo el mínimo de 4,5. |
| Fondo | `gray-100` (página), `gray-50`, tarjetas blancas | |
| Pendiente | `amber-50` / `amber-600..800` | `amber-600` sobre `amber-50` da 3,07:1 (insuficiente). |
| Aprobada | `emerald-50/100` / `emerald-700` | |
| Rechazada | `red-50/100` / `red-700` | |
| Turno día / noche | `#dbeafe` (azul) / `#f3e8ff` (lila) | |
| Fin de semana | `#f3f4f6` y etiqueta `orange-600` sobre `orange-100` (3,11:1) | |
| Login | degradado `indigo-600 → blue-500 → teal-400` | |

- **Tipografía:** Inter 400 a 900. Se usa sobre todo `text-xs` 12 px (378 usos) y `text-sm` 14 px (239), además de tamaños de 8 a 11 px en el calendario. Pesos muy altos: `font-bold` (360) y `font-black` (137).
- **Espaciado y radios:** escala de Tailwind (`px-3`, `py-2`, `p-4`); `rounded-xl` 12 px (234 usos), `rounded-full` (120), `rounded-lg` 8 px, `rounded-2xl` 16 px.
- **Sombras:** muchas (`shadow-sm`, `shadow-md`, `shadow-xl`), además de degradados en las cabeceras de formulario.
- **Componentes:** barra lateral blanca con el ítem activo en `indigo-100`; encabezado blanco con selector de servicio; pestañas públicas como botones redondeados con emoji (📅 📝 📋); tarjetas blancas `rounded-2xl` con sombra; formularios con etiquetas en `text-xs` mayúsculas; estados en chips de color con texto en mayúsculas ("PENDIENTE", "APROBADA").
- **Calendario:** celdas blancas con bordes grises, chips de turno azules o lilas con el nombre, y feriados como celda índigo completa. **No existe** un estado "lleno" ni "libre" de cupo.

### 3.3 Diferencias entre las dos, y contra la referencia

| Elemento | Referencia (encargo) | App nueva | App anterior |
|---|---|---|---|
| Encabezado blanco con "Ausentismos · Kinesiología HPM" | ✔ | ✔ igual | ✘ blanco, pero dice "HospitalSys" / "Calendario Público de Turnos" |
| "Pedir días" en píldora verde azulado | ✔ | ✔ (`#0e6b7c`, píldora de 52 px) | ✘ "Hacer Solicitud" / "Nueva Solicitud" en índigo con radio de 12 px |
| Pestañas de texto "Calendario", "Mis solicitudes" | ✔ | ✔ (la activa como píldora rellena) | ✘ botones con emoji: "Calendario / Hacer Solicitud / Estado Solicitudes" |
| Paleta | verde azulado y blanco | verde azulado, blanco y semánticos | índigo, grises y semánticos de Tailwind |
| Tipografía | — | Outfit + DM Sans | Inter |
| Sombras | — | ninguna | muchas, y degradados |
| Accesibilidad | — | 44 px táctiles, contraste ≥ 4,5 en todo texto, estado con texto | textos de 8 a 12 px y varios contrastes < 4,5 |

**Conclusión para la guía visual:** la base es el CSS de la app nueva. Habría que limpiar los restos de Gerson 1 (comentario, zebra crema, backdrop, `install.php`, impresión) y los tokens que no se usan (`--marca-vivo`, `--acento-texto`), y darle un nombre honesto a `--arena`. De la app anterior no conviene tomar colores; solo patrones útiles, como el color por turno día/noche si se mantiene el calendario de turnos.

Capturas a 375 px con datos ficticios: `docs/capturas/nueva-calendario-375.jpg` y `docs/capturas/anterior-calendario-375.jpg`.

---

## 4. Bugs y problemas

"**Reproducido**" significa que lo ejecuté y vi el resultado. "**Por lectura**" significa que lo deduje del código sin poder ejecutarlo, y lo indico en cada caso.

### 4.1 App anterior

#### 🔴 Pierde o corrompe datos, o hay un fallo de seguridad

| # | Problema | Cómo se comprobó | Causa y archivo |
|---|---|---|---|
| **B1** | **Cualquier persona, sin cuenta, puede leer, modificar, aprobar o borrar cualquier solicitud y falsificar el registro de auditoría.** | Por lectura de `firestore.rules` (no lo probé contra el proyecto real). Debe confirmarse que las reglas desplegadas son las mismas: en Firebase Console → Firestore → Reglas. | `firestore.rules`: `shiftRequests`, `auditLog` y `notifications` tienen `allow read, write: if true`. El `projectId` y el `apiKey` están en el JS público (que estén ahí es normal en Firebase; lo grave son las reglas). |
| **B2** | **Datos personales de toda la nómina legibles por cualquiera:** RUT, correo, teléfono, edad, saldos y **licencias médicas**. | Por lectura de las reglas. Además, **reproducido** en la interfaz: el formulario público muestra el RUT de todos en la lista desplegable ("Kine Dos Ficticio (33333333-3)"). | `firestore.rules` (`users`: `allow read: if true`) y `components/RequestFormView.tsx:433-437`. |
| **B3** | **Cualquier usuario autenticado puede convertirse en administrador o modificar los saldos y datos de otra persona.** Y si el registro por correo está activo en Firebase Auth (es el valor por defecto), cualquiera puede crearse una cuenta con la API pública. | Por lectura. | `firestore.rules` (`users`: `allow write: if request.auth != null`, sin revisar el rol ni el dueño del documento). Los roles solo se revisan en la interfaz (`Sidebar.tsx:75`, `RequestsAdminView`). `AuthView.tsx` incluso trae el selector "Administrador" en el registro. |
| **B4** | **La app dice "¡Solicitud registrada con éxito!" aunque no se guardó, y la solicitud se pierde al recargar.** | **Reproducido** con el Firebase simulado y una escritura rechazada: la pantalla de éxito apareció, en la base había 0 solicitudes nuevas, y tras recargar desapareció también de `localStorage`. En producción, una falla de red queda en cola gracias al caché de Firestore; esto ocurre cuando la escritura es **rechazada** (reglas, cuota, datos inválidos). | `App.tsx:644-648` captura el error del `setDoc` y sigue adelante. `App.tsx:406-421` sobrescribe `localStorage` con la lectura del servidor **antes** de "sincronizar", así que el respaldo local nunca se sube. |
| **B5** | **Saldos corruptos: "Revertir a pendiente" y volver a aprobar descuenta dos veces.** | **Reproducido**: FL de 7 días con saldo 15 → aprobar = 8 → revertir a pendiente = 8 (no devuelve el saldo, los turnos siguen sin asignar y el reemplazo sigue activo) → aprobar = **1**. | `App.tsx:801-813`: solo descuenta si el estado anterior no era `approved`, y el cambio a `pending` no tiene ninguna rama que deshaga lo hecho. El botón está en `RequestsAdminView.tsx:553`. |
| **B6** | **Actualizaciones perdidas y estados a medias:** aprobar una solicitud escribe 5 a 20 documentos uno por uno, sin transacción ni lote, y si alguno falla, el error se ignora (`catch (e) {}`). Además, el perfil completo (`profileData`) se reescribe desde la copia que tenía el navegador, así que dos coordinadores aprobando a la misma persona a la vez se pisan los cambios. | Por lectura (la carrera entre dos administradores no la reproduje). | `App.tsx:711-1091` (`updateShiftRequestStatus`) y `App.tsx:1093-1181` (`deleteShiftRequest`). |

#### 🟠 Regla mal aplicada o flujo roto

| # | Problema | Cómo se comprobó | Causa y archivo |
|---|---|---|---|
| B7 | **El saldo de feriado cuenta días corridos, no hábiles:** un FL de lunes 14 a domingo 20 descuenta 7 días. | **Reproducido** (saldo 15 → 8). | `App.tsx:764-773` (diferencia de fechas + 1, sin excluir fines de semana ni feriados). |
| B8 | **No existe cupo por equipo**, ni aviso de día lleno ni sobre cupo con justificación. | Por lectura: no hay ninguna referencia a cupo en el código. | **Diferencia de reglas con SPEC §4.2** (ver la §5). |
| B9 | **No distingue la calidad jurídica** (titular, contrata u honorarios) ni aplica la regla de FL ≥ 5 días. | Por lectura: el modelo `User` no tiene ese campo. | **Diferencia de reglas con SPEC §4.2.** |
| B10 | **Lleva saldos oficiales** (feriado, administrativos) y los descuenta al aprobar. | Reproducido (ver B5 y B7). | **Diferencia con el SPEC y con tu encargo** ("no lleva saldos oficiales"). |
| B11 | **Registra licencias médicas** (tipo "Licencia" y `profileData.licenses`). | Por lectura. | **Diferencia con SPEC §1** (no-objetivo). Además, son datos de salud expuestos por B2. |
| B12 | Se puede pedir con **fecha pasada**, y una persona puede tener **solicitudes superpuestas**. | Por lectura: la fecha de inicio no tiene `min` y no hay validación de superposición. | `RequestFormView.tsx` (solo `endDate` tiene `min={startDate}`). |
| B13 | **"Cancelar" borra la solicitud** en vez de anularla; no queda historia. El funcionario solo puede cancelar las pendientes, sin plazo. Coordinación puede **eliminar** cualquier solicitud. | Por lectura. | `MyRequestsView.tsx:278-288` y `App.tsx:1154-1157` (`deleteDoc`). **Diferencia con SPEC §4.5** (estado anulada, plazo de las 23:59). |
| B14 | **El rechazo no exige motivo.** | Por lectura. | `RequestsAdminView.tsx:178-184` (`rejectionReason.trim() \|\| undefined`). **Diferencia con SPEC §4.5.** |
| B15 | **La reversión no es simétrica:** al pasar de autorizada a rechazada se restauran los turnos que tienen `originalUserId` igual al solicitante en el rango, lo que puede **deshacer reemplazos de otra solicitud** de la misma persona en esas fechas. | Por lectura. | `App.tsx:1045-1064` y `1134-1151` (filtran por persona y rango, no por solicitud). |

#### 🟡 Visual o de usabilidad

| # | Problema | Cómo se comprobó | Causa y archivo |
|---|---|---|---|
| B16 | A 375 px, las pestañas del portal público se salen de la pantalla ("Estado Solicitudes" queda cortado). | **Reproducido** (`docs/capturas/anterior-calendario-375.jpg`). | `App.tsx:1428-1463`. |
| B17 | El PDF del calendario marca **"(Licencia)"** a cualquier persona con un permiso aprobado, aunque sea un feriado o un administrativo. | Por lectura. | `components/Header.tsx:45-56`. |
| B18 | Contraste insuficiente (`gray-400` 2,54:1; ámbar y naranjo cerca de 3:1) y textos de 8 a 11 px en el calendario. | Medido. | Clases de Tailwind en varios componentes. |
| B19 | Error de TypeScript: `AbsenceReason.UNPAID_LEAVE` no existe, así que las ausencias "sin goce" quedan sin motivo. | **Reproducido** con `tsc --noEmit`. | `App.tsx:872`, `types.ts:42-47`. |
| B20 | Feriados fijos en el código hasta 2035, duplicados en `utils/extraHours.ts` y `CalendarView.tsx`, sin edición. Tailwind por CDN en modo desarrollo. | Por lectura. | Esos dos archivos e `index.html`. |
| B21 | El formulario es largo para el celular: motivo obligatorio, casilla de veracidad obligatoria, teléfono y tres tarjetas de trámite. | Reproducido al llenarlo. | `RequestFormView.tsx`. |

### 4.2 App nueva

| # | Gravedad | Problema | Cómo se comprobó | Causa y archivo |
|---|---|---|---|---|
| **N1** | 🔴 | **Coordinación abierta por defecto: cualquiera con el enlace autoriza, rechaza, edita la nómina y descarga el respaldo completo (con RUT).** El README lo presenta como opción ("renombra la carpeta"), pero esconder la URL no protege. Se contradice con SPEC §1 ("que cualquier persona sin la clave pueda autorizar…" está en los anti-objetivos). | **Reproducido**: `GET api.php?r=coord/respaldo` sin login → HTTP 200 y 77 824 bytes. `coord/sesion` entrega un token CSRF sin clave. | `config.example.php:19` (`'coord_sin_clave' => true`) y `lib/auth.php:17-21`. |
| **N2** | 🔴 | **Exposición de calidad jurídica y de RUT → nombre sin login:** `solicitudes/simular` devuelve nombre, equipo y **calidad** de cualquier `funcionario_id`, y con solo un RUT devuelve a quién pertenece. Sin límite de intentos. | **Reproducido** (script en una base temporal). | `lib/reglas.php:287-293` (respuesta de `$dry`), `lib/reglas.php:47-52`, `lib/handlers_public.php:80-83`. Contradice SPEC §7 ("nunca expone… calidad jurídica"). |
| N3 | 🟠 | **El límite de 10 envíos por IP por hora bloquea a todo el hospital**: todos los PC salen a internet por la misma IP pública. Lo mismo pasa con el bloqueo de login de coordinación (5 intentos por IP): una persona que se equivoca bloquea a todos por 15 minutos. | **Reproducido**: 11 personas distintas desde la misma IP; la 11.ª recibió "Has enviado muchas solicitudes…". | `lib/handlers_public.php:89-96` y `lib/auth.php:52-56`. Es un **punto débil del SPEC** (§5.1 b y §5.2), que se resuelve en parte con login por usuario. |
| N4 | 🟠 | **Cualquiera puede pedir a nombre de otro** (riesgo aceptado en SPEC §7), y **"Mis solicitudes" muestra a cualquiera las solicitudes de otra persona, con el motivo de rechazo.** | Por lectura y por diseño. | `lib/handlers_public.php:112-127`. Desaparece con el login (ver la Fase 2). |
| N5 | 🟠 | Se siembran **19 equipos**, no 17: agrega "Oncología infantil" y "Neurología infantil", que no están en SPEC §3.1. | Reproducido (`dev-data`: 19 equipos). | `lib/db.php:134`. **Pregunta:** ¿existen esos equipos? Tú hablas de 17. |
| N6 | 🟡 | A 375 px, los controles del mes (anterior, título, siguiente, Hoy, selector, Imprimir) usan unas 4 filas y el calendario empieza a media pantalla. Los textos del día miden 10 a 11 px. | **Reproducido** (`docs/capturas/nueva-calendario-375.jpg`). | `public/assets/app.css` (`.cal-ctrl`, `.dia .est`, `.dia .sub`) e `index.html`. |
| N7 | 🟡 | Restos del sistema Gerson 1 (comentario, zebra crema, backdrop, `install.php`, impresión) y tokens sin uso. | Por lectura. | `public/assets/app.css` y `public/install.php:32-34`. |
| N8 | 🟡 | La devolución de un cambio de turno no aparece en el calendario. | Por lectura. | `lib/reglas.php:147-159` (no se guarda en `solicitud_dias`). |
| N9 | 🟡 | `.htaccess` y el bloqueo de `install.php` no se pueden verificar con el servidor local de PHP, que ignora `.htaccess`. Hay que comprobarlos en DreamHost (SPEC §9.4). | No verificable en local. | — |

### 4.3 Puntos débiles de `SPEC.MD.md`

| # | Punto | Por qué importa |
|---|---|---|
| S1 | **El medio día suma 0,5 sin distinguir mañana de tarde.** Reproducido: 4 ADM de la mañana en un equipo con cupo 2 dan ocupación 2/2, todos aceptados sin sobre cupo, y **4 personas faltan en la mañana**. | La regla vigente produce justo lo que el cupo quiere evitar. **No lo cambio yo: es pregunta tuya** (ver la §5 y la Fase 2). |
| S2 | El límite por IP y el bloqueo de login por IP no sirven si todo el hospital comparte una IP (ver N3). | Con login, el límite debería ser por cuenta. |
| S3 | La anulación con RUT no es una verificación real: el RUT es casi público en Chile y la app anterior lo mostraba a todos. | Con login se reemplaza por "solo el dueño o coordinación". |
| S4 | La regla de FL ≥ 5 días se puede **partir o juntar** a conveniencia: dos FL de 5 días no usan cupo, pero uno de 4 sí. No dice qué hacer si alguien anula parte de un FL largo (solo se anula completo). | Conviene definir si el umbral es por solicitud o por período continuo. |
| S5 | §3.2 permite pedir "fuera de nómina", lo que con login deja de tener sentido o cambia de forma. | Va en la Fase 2. |
| S6 | No define **respaldos automáticos** (solo un botón) ni cuánto tiempo se guarda la auditoría y la IP (datos personales). | Riesgo de pérdida y de retención indefinida. |
| S7 | Cambiar el cupo de un equipo no es retroactivo: si se baja, los días ya ocupados quedan sobre cupo sin marca. | Coordinación debería ver un aviso. |
| S8 | No dice quién "es" coordinación ni jefatura, porque usa clave compartida y "Resuelto por" en texto libre. | Se resuelve con roles por persona. |

---

## 5. Funciones que solo existen en una de las dos

**Decide tú si se quedan.** Las que contradicen el SPEC están marcadas con ⚠ (son diferencias de reglas y no las elijo yo).

### Solo en la app anterior

| Función | Comentario |
|---|---|
| **Calendario de turnos reales** por servicio (quién está de día o de noche cada fecha), con asignación, intercambio, reasignación, creación y borrado en lote | Es otro producto (gestión de turnos). El SPEC dice que el sistema **no** valida contra un calendario de turnos. ⚠ |
| Reemplazos elegidos **de la nómina por cada turno**, y la aprobación que **reasigna el turno** en el calendario | Útil si se mantiene el calendario de turnos. |
| Cambio de turno que **intercambia los turnos** al aprobar | Ídem. |
| **Turnos extra y cálculo de horas extra** (diurnas o festivas, viernes, vísperas) | Fuera del alcance de ausentismos. |
| **Saldos** de feriado y administrativos editables, con descuento automático ⚠ | Contradice el encargo ("no lleva saldos oficiales"). |
| **Licencias médicas**, ausencias injustificadas y permisos sin goce ⚠ | Contradice SPEC §1. |
| Dashboard del día (turnos de hoy, por reasignar, ausentes hoy) | Se podría adaptar a "ausentes hoy". |
| "Consultar personal libre" para un día | Útil para buscar reemplazos. |
| Notificaciones internas y registro de cambios visible en pantalla | N tiene auditoría, pero no una vista. |
| PDF del calendario | N tiene impresión del navegador. |
| Perfil propio, foto, teléfono, edad, color por servicio, borrar la propia cuenta | Datos no necesarios. |
| Despliegue automático con GitHub Actions | **Recomendable conservar la idea.** |
| Login por usuario con correo | Ya decidiste que haya login; la forma se ve en la Fase 2. |

### Solo en la app nueva

| Función | Comentario |
|---|---|
| Cupo por equipo con ocupación, sobre cupo con justificación y verificación segura ante concurrencia | Regla central del SPEC. |
| Calidad jurídica y regla de FL largo y honorarios | SPEC §4.2. |
| Feriados editables por coordinación | |
| Importación CSV de nómina con validación de RUT | |
| Exportación CSV y resumen anual por persona | |
| Respaldo `.sqlite` desde la interfaz | |
| Solicitudes "fuera de nómina" y su vinculación | Cambia con el login. |
| Código legible `AUS-2026-0001` | |
| Estado "anulada" con plazo, y "vencidas sin resolver" | |
| Simulación en vivo del cupo antes de enviar | |
| Vista de turnantes (turno, unidad, reemplazo) sin calendario de turnos | |
| Honeypot, límite por IP, CSP y CSRF | |
| Tests automatizados (60 verificaciones, con concurrencia) | |
| Versión para imprimir el calendario | |

---

## 6. Datos

**App nueva:** **no tiene datos reales.** `dev-data/ausentismos.sqlite` tiene 19 equipos y 0 funcionarios, solicitudes, feriados y auditoría. `nomina-hpm.csv` (63 filas, ignorado por git) parece la **nómina real** con RUT: no lo cargué en ninguna parte. No sé si la app nueva está desplegada en DreamHost ni si tiene datos allí (**pregunta**).

**App anterior:** **no pude contar los registros** sin conectarme al Firestore de producción, y no lo hice. Que tenga un flujo de despliegue a DreamHost y un proyecto `administrador-de-turnos-hpm` sugiere que está en uso. **Pregunta: ¿tiene datos reales?** Si los tiene, para contarlos y migrarlos propongo:
1. Que **tú** generes una exportación de solo lectura: en Firebase Console → Firestore, o con un script que yo te prepare y que corras con tu cuenta. Así obtenemos un JSON local por colección, que se trabaja solo sobre esa copia.
2. Contar: `users`, `shifts`, `shiftRequests` y `auditLog`, más las solicitudes "incrustadas" en `users.profileData.permissionRequests` y `replacementAbsences`.

**Qué tan fácil es llevarlos al modelo unificado:**

| Dato anterior | Destino | Dificultad |
|---|---|---|
| `users` (nombre, RUT, servicio, `isDiurnalWorker`) | `funcionarios` | **Media**: el RUT viene sin validar y con puntos; **falta la calidad jurídica** y la **jornada** se deduce mal (`isDiurnalWorker` marca "diurno que cubre turnos", no "diurno"). Habrá que completarlas a mano o cruzarlas con `nomina-hpm.csv` por RUT. |
| `services` | `equipos` | **Media**: los nombres pueden no calzar con los 17 equipos y no hay cupo. Requiere una tabla de equivalencias que tú apruebes. |
| `shiftRequests` + `permissionRequests` + `replacementAbsences` | `solicitudes` + `solicitud_dias` | **Alta**: hay que quitar duplicados de 3 fuentes por `id`, deducir el tipo desde el texto libre (`permissionType`), y descartar o separar las licencias, las ausencias injustificadas y los permisos sin goce, que no existen en el modelo nuevo. El estado se traduce así: `pending/approved/rejected` → `pendiente/autorizada/rechazada`; las cancelaciones se perdieron porque se borraban. El consumo de cupo se **recalcula** con las reglas nuevas. Los turnantes necesitan turno y unidad, que hay que deducir del `Shift` vinculado. |
| Saldos (`vacations`, `adminDays`) | — | **No se migran** (fuera de alcance), salvo que decidas lo contrario. Además, están corruptos por B5 y B7. |
| `shifts`, horas extra | — | Solo si decides conservar el calendario de turnos. |
| `auditLog`, `notifications` | `auditoria` (opcional) | Baja, pero tiene poco valor y pudo ser modificado por cualquiera (B1). |

---

## 7. Preguntas que surgieron en esta fase

Las ordeno y amplío en la Fase 2. Las que bloquean la propuesta:
1. **¿La app anterior tiene datos reales en uso?** ¿Cuántas personas la usan y desde cuándo?
2. **¿La app nueva está publicada en DreamHost?** ¿Con qué `config.php` (en particular `coord_sin_clave`)?
3. **¿Quieres conservar el calendario de turnos y las horas extra** de la app anterior? ¿O la app unida es solo de ausentismos, como dice el SPEC?
4. **Medio día (S1):** ¿AM y PM deberían contar por separado contra el cupo?
5. **¿Existen "Oncología infantil" y "Neurología infantil"?** (N5)
6. ¿Puedes subir `docs/referencia-estilo.png`?
7. Las diferencias de reglas B8 a B14 (sin cupo, sin calidad, saldos, licencias, cancelar = borrar, rechazo sin motivo): asumo que **rige el SPEC** porque así lo dijiste, pero confírmalo en cada una que la app anterior aplique distinto a propósito.

**Urgente, aunque no sea parte de este trabajo:** si la app anterior está en uso, B1 a B3 exponen hoy los RUT, teléfonos y licencias médicas del equipo, y permiten que cualquiera apruebe solicitudes. Revisar las reglas desplegadas en Firebase Console es una tarea de minutos. Te propongo hacerlo antes de seguir con la unificación.

---

## 8. Respuestas recibidas (19-11-2026)

| Pregunta | Respuesta | Consecuencia |
|---|---|---|
| ¿La app anterior tiene datos reales? | **No, solo pruebas.** | No hay datos de Firebase que migrar. La "migración" se reduce a la carga inicial de la nómina (`nomina-hpm.csv`) y, si aplica, de los saldos iniciales. B1–B3 no exponen datos reales hoy, pero conviene apagar o cerrar ese proyecto Firebase. |
| ¿La app nueva está publicada? | **No.** | N1 no afecta a nadie hoy. Se corrige antes de publicar. |
| Alcance | **Todo**: ausentismos + calendario de turnos + turnos extra y horas extra. | Cambia el SPEC §1 y §4.3 (ver `docs/propuesta.md`). |
| Medio día | **AM y PM por separado**: cada bloque con su propio cupo; un día completo ocupa ambos. | Cambia el SPEC §4.2 y el test de "AM + PM suman 1". |
| Equipos | **Son 19** (incluye Oncología infantil y Neurología infantil). | N5 deja de ser bug; se corrige la cifra en el SPEC. |
| Saldos | **Como la app anterior**: se descuentan al aprobar (corrigiendo días hábiles y el doble descuento). | ⚠ Cambia una regla del encargo ("no lleva saldos oficiales"). Quedo registrándolo como decisión tuya; los detalles van como preguntas en la Fase 2. |
| Tipos de ausentismo | **Agregar licencias** y que **coordinación pueda crear otros tipos** (lista ampliable). | Cambia el SPEC §1 y §4.1. Cada tipo nuevo necesita reglas (cupo, saldo, quién lo pide); van como preguntas en la Fase 2. |
| Referencia visual | **Enviarás una nueva referencia.** | La guía visual de la Fase 3 queda a la espera de esa imagen. El análisis del §3 sigue siendo válido como inventario de lo actual. |
