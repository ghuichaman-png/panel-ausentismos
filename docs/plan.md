# Plan de implementación (Fase 4)

Base: `SPEC-v2.md`. Cada paso es **un commit** en la rama de trabajo, deja la app funcionando y se puede comprobar. Los bugs llevan primero un test que los reproduce (rojo) y luego la corrección (verde). Antes de cada commit se corre `php tests/run.php` completo.

**Antes de empezar:** confirmar las interpretaciones [a confirmar] de SPEC-v2 §12.1 a 12.7. Los pasos que dependen de cada una están marcados con (§12.x).

**Bugs de la app anterior:** su código se descarta junto con Firebase, así que no se "corrigen" ahí. Sus fallas sí se cubren con **tests de regresión** en la app unida: doble descuento (B5), días corridos (B7), errores ocultos (B4), estados a medias (B6) y reversión que pisa otras solicitudes (B15). Cada caso aparece en el paso que construye la función equivalente.

---

## Etapa 0 — Bugs 🔴 de la app nueva (primero)

| # | Paso | Test primero | Hecho cuando |
|---|---|---|---|
| 0.1 | **N1: coordinación sin clave.** Eliminar `coord_sin_clave` (de `config.example.php`, `cfg_defaults` y `lib/auth.php`). | Sin sesión, `coord/solicitudes`, `coord/respaldo` y `coord/sesion` responden 401 y no entregan CSRF. | El test pasa, y `GET coord/respaldo` sin sesión da 401 en el servidor local. |
| 0.2 | **N2: exposición de datos en la simulación.** `solicitudes/simular` no devuelve calidad, nombre ni equipo de una persona distinta de la que inició sesión. Mientras no exista el login, deja de devolver los datos de la persona; el RUT ajeno no revela nada. | La simulación con `funcionario_id` ajeno o con un RUT existente no contiene `calidad` ni `nombre`. | El test pasa. Script de reproducción de la Fase 1 sin fuga. |

## Etapa 1 — Cimientos

| # | Paso | Hecho cuando |
|---|---|---|
| 1.1 | **Migraciones de esquema** versionadas (`PRAGMA user_version`, `lib/migraciones.php`), que se pueden repetir. La migración 1 es el esquema actual. | Correr la migración dos veces no cambia nada; los tests existentes pasan. |
| 1.2 | **Ordenar los tests** en módulos (`tests/casos/*.php`), con un ayudante para crear bases temporales y un "cliente HTTP" interno para probar rutas con sesión. | Las mismas 60 verificaciones pasan y queda lista la estructura para probar rutas. |
| 1.3 | **19 equipos** en la siembra (sin cambio de código; se documenta) y la nómina con `telefono` y `rol` (migración 2). | La importación CSV acepta `telefono`; test de upsert. |

## Etapa 2 — Login por usuario (opción A)

| # | Paso | Hecho cuando (tests) |
|---|---|---|
| 2.1 | Tablas `credenciales` y `sesiones` (migración 3). Generar código de activación: 8 caracteres sin ambiguos, hash, 7 días, un solo uso. | Código válido, vencido y usado. |
| 2.2 | `auth/activar` (RUT + código → crear PIN, rechaza PIN triviales) y `auth/ingresar` (RUT + PIN). Bloqueo **por cuenta**: 5 errores → 15 minutos, luego 1 hora, luego manual. Límite por IP alto. | Bloqueo por cuenta; otra cuenta desde la misma IP no se bloquea. |
| 2.3 | Sesiones: cookie con token opaco y hash en la base; 180 días que se renuevan; "computador compartido" de 30 minutos; `auth/salir`, `auth/sesiones` (listar y revocar), `auth/pin`. | Expiración, renovación, revocación, y código nuevo que invalida el PIN. |
| 2.4 | **Control de acceso** en el enrutador: toda ruta exige sesión; `coord/*` y `jefatura/*` exigen rol, verificado en el servidor. CSRF en toda escritura. Se elimina la clave compartida. | Sin sesión → 401; funcionario en `coord/*` → 403; sin CSRF → 403. |
| 2.5 | Las solicitudes usan **la persona de la sesión**. Se eliminan "fuera de nómina", el honeypot y el nombre en el formulario. **La anulación ya no pide RUT**: solo la dueña o coordinación. "Mis solicitudes" muestra solo las propias. `resuelta_por` es el `funcionario_id`. "Registrar en nombre de" (coordinación) queda auditado. Límite de envíos **por cuenta**. | Un funcionario no anula la solicitud de otro; la búsqueda de nómina y el calendario no devuelven RUT, calidad ni teléfono. |
| 2.6 | Interfaz: pantalla de **ingreso y activación** (RUT, PIN de 6 casillas, "computador compartido") y "Mi perfil" con cambio de PIN y sesiones. Coordinación: generar código, desbloquear y cerrar sesiones. | Prueba manual a 375 px: activar, ingresar, pedir y salir. |

## Etapa 3 — Reglas nuevas de ausentismo

| # | Paso | Hecho cuando (tests) |
|---|---|---|
| 3.1 | **Tipos configurables** (migración 4: `tipos_ausentismo`, con FL, ADM, CT y LIC precargados). El motor de reglas lee la configuración del tipo. | Todos los tests de reglas pasan igual con los tipos precargados. Un tipo nuevo "sin cupo y sin saldo" se comporta según su configuración. |
| 3.2 | **Cupo por bloque AM/PM** (migración 5: `bloque_am` y `bloque_pm` en `solicitud_dias`). Se reemplaza el test "AM + PM suman 1". | El 3.º AM con cupo 2 queda sin cupo; un día completo ocupa ambos bloques; regla FL ≥ 5; honorarios; **concurrencia por bloque** con 2 procesos. |
| 3.3 | **Saldos como libro de movimientos** (migración 6). Asignación anual de FL 15 y ADM 6 que se puede repetir; ajustes con motivo; saldo calculado; **bloqueo** si no alcanza; las pendientes reservan (§12.6); FL que cruza de año. | Lunes a domingo descuenta 5 (regresión **B7**); ½ ADM = 0,5; autorizar → rechazar → autorizar sin doble descuento (regresión **B5**); dos solicitudes simultáneas no superan el saldo; anular devuelve. |
| 3.4 | **Licencias:** solo las registra coordinación, nacen autorizadas (§12.5), no consumen cupo ni saldo y se ven como "Licencia". | Ocupación sin cambios con una licencia; en el calendario público aparece "Licencia". |
| 3.5 | Resolución y anulación: se quita cualquier "volver a pendiente" o borrado; revertir vuelve a verificar cupo, saldo y turnos. **Todo error se informa** (sin `catch` mudo). | Revertir sin saldo exige un ajuste o falla con un mensaje claro (regresión **B4**: no hay "éxito" si no se guardó). |

## Etapa 4 — Turnos y horas extra

| # | Paso | Hecho cuando (tests) |
|---|---|---|
| 4.1 | Modelo de **turnos** (migración 7) y CRUD de coordinación: uno por uno y **en lote** (patrón por unidad y mes). No se puede borrar un turno con solicitudes. | Lote sin duplicados; validaciones. |
| 4.2 | **Turnantes con turno exigido** (§12.1 y §12.2): ADM y CT sobre un turno propio; FL por rango con sus turnos listados y un reemplazo de la nómina por turno. | Sin turno cargado → rechazo; reemplazo obligatorio. |
| 4.3 | Al **autorizar**, se marca `cubierto_por` y `es_extra` (§12.4) **en la misma transacción**. Anular o revertir restaura **solo los turnos de esa solicitud**. | Una falla a mitad de camino revierte todo (regresión **B6**); dos solicitudes de la misma persona no se pisan (regresión **B15**). |
| 4.4 | **Cambio de turno** que intercambia titulares, con o sin devolución, en una transacción. | Intercambio y restauración. |
| 4.5 | **Licencia de un turnante** → sus turnos quedan "sin cubrir"; coordinación asigna el reemplazo. | Turnos marcados y listados en "Hoy" y en el calendario de turnos. |
| 4.6 | **Horas extra:** se porta `extraHours.ts` a `lib/horas_extra.php`, con feriados de la tabla. Resumen por persona y mes, y CSV. | Una prueba por cada fila de SPEC-v2 §4.7, más la corrección manual y el medio día. |
| 4.7 | **Interfaz de turnos:** calendario de turnos (coordinación), "Mis turnos", "personal libre" y asignar reemplazo. **Punto de control:** si el JavaScript sin framework se vuelve inmanejable, me detengo y te propongo alternativas. | Prueba manual a 375 px y en escritorio. |

## Etapa 5 — Pantallas restantes y guía visual Vital

| # | Paso | Hecho cuando |
|---|---|---|
| 5.1 | Copiar Vital **sin modificar** a `public/assets/vital/` (tokens, CSS de componentes, Figtree y licencia). Quitar Google Fonts y dejar la CSP en `'self'`. | La página carga Figtree local; la CSP no reporta errores. |
| 5.2 | Reescribir `app.css` con los tokens de Vital: NavBar de vidrio, botones de 44 px, escala reducida y componentes propios (§8.7). Tema oscuro automático. Sin restos de Gerson 1. | `grep -i gerson` sin resultados; capturas en claro y oscuro a 375 px. |
| 5.3 | Calendario con **franjas AM/PM**, controles del mes en una fila y `aria-label` por día. | Capturas de los estados libre, parcial y lleno por bloque; prueba con teclado. |
| 5.4 | "Pedir días" con el resumen en vivo de cupo y **saldo**; "Mi perfil" con MetricCards de saldo. | Prueba manual: pedir un ADM en menos de 30 segundos con la sesión abierta (cronometrado). |
| 5.5 | Coordinación: bandeja, saldos (ajustes), licencias, horas extra, registro de cambios y "Hoy". Jefatura: tipos, equipos, feriados, roles y respaldo. | Prueba manual de cada sección; los tests de rol ya cubren el acceso. |

## Etapa 6 — Carga inicial (paso propio; equivale a la "migración de datos")

No hay datos que migrar desde Firebase ni desde una app nueva publicada. Este paso carga los datos de partida.

| # | Paso | Hecho cuando |
|---|---|---|
| 6.1 | `herramientas/carga-inicial.php --csv=nomina-hpm.csv [--dry-run]`: upsert de la nómina por RUT (**sin duplicar ni borrar**, y **sin modificar el CSV**), asignación anual de saldos que se puede repetir, e informe de filas con error (p. ej. calidad faltante). | Test: correrlo dos veces da el mismo resultado; `--dry-run` no escribe; el hash del CSV no cambia. Prueba sobre una **copia local**. |
| 6.2 | `--codigos`: genera códigos de activación para quienes no tienen PIN, y los escribe en un archivo **local** (fuera del repo, ignorado por git) para que coordinación los entregue uno a uno. | Repetirlo solo genera códigos para quienes aún no se activan y avisa antes de reemplazar uno vigente. |

## Etapa 7 — Despliegue y verificación final

| # | Paso | Hecho cuando |
|---|---|---|
| 7.1 | `.github/workflows/deploy.yml`: corre los tests y, si pasan, sube por SFTP `public/` y `lib/`. **Excluye** `config.php`, los datos, `tests/`, `herramientas/` y los CSV. Usa los secretos del repositorio. | Workflow revisado. **No se activa** hasta que tú configures los secretos y lo apruebes. |
| 7.2 | README: despliegue, configuración, respaldo (manual más `sqlite3 .backup` programado con cron de DreamHost si lo tienes) y cómo publicar sin perder datos. | — |
| 7.3 | **Verificación final:** todos los tests de SPEC-v2 §9 (incluida la concurrencia por bloque y por saldo), los de login, los de cada bug y los de regresión. Interfaz a 375 px y en escritorio, en claro y oscuro. Resumen para ti: qué quedó, qué no, qué decidí y cómo publicar. | Salida de los tests adjunta. La comprobación de `.htaccess` en DreamHost queda en una lista para hacer contigo al publicar (en local no se puede). |

---

**Orden y tamaño:** unos 32 commits. Las etapas 0 a 3 dejan una app de ausentismos con login completa y probada. La etapa 4 es la más grande. Me detengo y te informo al terminar cada etapa.
