# Fase 2 — Propuesta para unir las dos apps

Fecha: 19-11-2026 · Base: `docs/analisis.md` (incluye tus respuestas en la §8).

**Punto de partida, según tus respuestas:**
- La app anterior no tiene datos reales y la nueva no está publicada, así que **no hay datos que proteger ni migrar desde Firebase**.
- El alcance es **todo**: ausentismos con cupo, calendario de turnos, turnos y horas extra, **saldos** que se descuentan, **licencias** y tipos de ausentismo **ampliables por coordinación**.
- El medio día cuenta **por bloque** (mañana y tarde por separado).
- Son **19 equipos**.
- El login es por usuario, y la restricción principal sigue siendo pedir un día en menos de un minuto desde el celular.

---

## 1. Login, el punto más delicado

### 1.1 Qué hace que el login agregue fricción

La fricción no viene de tener cuenta, sino de **tener que entrar cada vez** y de **olvidar la clave sin poder recuperarla**. Si el celular recuerda la sesión por meses, pedir un día con login es *más rápido* que hoy: desaparece el paso de buscar tu nombre en la lista y el de escribir el RUT para anular. El objetivo es entonces:
1. Entrar **una vez** por dispositivo.
2. Que la primera vez sea corta y sin correo.
3. Que recuperar el acceso no dependa del correo.

### 1.2 Tres opciones

| | **A. RUT + PIN de 6 dígitos (recomendada)** | **B. Enlace o QR personal, sin clave** | **C. RUT + contraseña, con recuperación por correo** |
|---|---|---|---|
| **Quién tiene cuenta** | Todos: funcionarios, coordinación y jefatura. | Todos. | Todos. |
| **Cómo entra** | RUT + PIN numérico de 6 dígitos. En el celular se teclea con el teclado numérico. | Abre en su celular un enlace o QR personal que le entrega coordinación, y ese dispositivo queda registrado. No escribe nada. | RUT (o correo) + contraseña de 10 caracteres o más. |
| **Primera vez, desde la nómina** | Al importar la nómina, cada persona recibe un **código de activación** de 8 caracteres, válido 7 días, que coordinación imprime o envía por WhatsApp. Ingresa RUT + código y crea su PIN. Toma unos 40 segundos, una sola vez. | Coordinación genera el enlace de cada persona y se lo envía. Un toque. | Coordinación crea la cuenta con una clave temporal, o la persona se registra con su RUT de la nómina y su correo, y verifica el correo. |
| **Duración en el celular** | 180 días que se renuevan con el uso, mientras no cierre sesión. Se puede revocar. | Igual: el dispositivo queda registrado hasta que se revoque. | Igual que A, si se usa "recordarme". |
| **Computadores compartidos del hospital** | Casilla "computador compartido": la sesión dura hasta cerrar el navegador o 30 minutos sin uso. | **Mal resuelto**: abrir el enlace en un PC compartido deja ese PC registrado. Habría que agregar un segundo método, que en la práctica es A o C. | Igual que A. |
| **Si olvida su clave y no tiene el correo a mano** | Le pide a coordinación un **nuevo código de activación** (en persona o por WhatsApp), entra y crea otro PIN. Queda en la auditoría. | Coordinación le genera otro enlace. | Si no tiene el correo, igual depende de coordinación, que le genera una clave temporal. |
| **Seguridad** | Un PIN de 6 dígitos tiene 1 millón de combinaciones. Se protege **bloqueando la cuenta** tras 5 intentos fallidos (15 minutos, y luego bloqueos más largos), **no por IP**, porque todo el hospital comparte una IP pública. El PIN se guarda con `password_hash`. | Débil: quien reciba el enlace reenviado o vea el QR entra como esa persona. Exige que los enlaces sean de un solo uso y que se puedan revocar. | La más fuerte en teoría, pero la gente reutiliza claves y las anota. Exige enviar correos desde DreamHost (configurable, pero puede caer en spam). |
| **Fricción** | Baja: 40 segundos una vez, después nada. | Mínima en el celular, alta en PC compartido. | Media o alta: claves largas, verificación de correo y recuperación por correo. |

**Roles** (iguales en las tres opciones):

| Rol | Puede |
|---|---|
| **Funcionario** | Ver el calendario (de ausencias y de turnos), pedir días, ver y anular **sus** solicitudes, proponer o aceptar cambios de turno y ver **su** saldo. |
| **Coordinación** | Todo lo anterior. Además: resolver solicitudes, gestionar el calendario de turnos y los reemplazos, registrar licencias, registrar una solicitud **en nombre de** alguien (queda auditado), ajustar saldos con un motivo, gestionar la nómina y generar códigos de activación. |
| **Jefatura** | Todo lo anterior. Además: configurar equipos y cupos, **tipos de ausentismo**, feriados, reglas de horas extra, asignar roles y descargar respaldos. |

### 1.3 Recomendación: opción A

- **Cumple la regla del minuto.** Con la sesión de 180 días, pedir un día es: abrir la app (instalable en la pantalla de inicio), tocar "Pedir días", elegir fecha y tipo, y enviar. Unos **20 a 30 segundos**, sin escribir nombre ni RUT.
- **No depende del correo**, ni para entrar ni para recuperar. Lo único que hace falta es hablar con coordinación, que ya es como funciona el equipo hoy.
- **Sirve en el celular y en el PC compartido** con el mismo método.
- **Se puede construir en PHP + SQLite en DreamHost** sin servicios externos.
- Se puede **agregar después** el ingreso con huella o rostro (*passkeys*, WebAuthn) como opción. No lo propongo para la primera versión, porque agrega complejidad y no todos los celulares lo soportan igual.

Descarto B como método principal porque el enlace reenviado equivale a la cuenta y no sirve en los PC compartidos. Descarto C porque agrega fricción (correo, claves largas) sin mejorar la recuperación, que igual terminaría pasando por coordinación.

---

## 2. Arquitectura de la app unida

### 2.1 Tres caminos

| | **1. Partir de la app anterior** (React + Firebase), corregirla y traer las reglas | **2. Partir de la app nueva** (PHP + SQLite + JavaScript sin compilar) y traer las funciones de la anterior **(recomendado)** | **3. Mezcla**: backend de la nueva (PHP + SQLite) con la interfaz React de la anterior, compilada en GitHub Actions |
|---|---|---|---|
| **Qué se reutiliza** | La interfaz de turnos, horas extra, perfiles y dashboard. | Reglas de cupo, transacciones, tests (60 verificaciones), nómina por CSV, exportación, respaldo e interfaz móvil. De la anterior se **portan** la lógica de turnos y horas extra (`utils/extraHours.ts`) y los flujos de reemplazo y cambio. | El backend de la nueva y los componentes React de turnos de la anterior. |
| **Cupo seguro ante dos solicitudes a la vez** | **Difícil**: las reglas de Firestore no pueden sumar ocupación. Hace falta Cloud Functions, que exigen el plan **pago** (Blaze). | Ya resuelto con `BEGIN IMMEDIATE` y probado. | Ya resuelto (backend). |
| **Login por usuario** | Firebase Auth sirve, pero el PIN por RUT no es nativo. Habría que reescribir todas las reglas de seguridad. | Hay que construirlo (tablas de sesiones y credenciales). Es acotado. | Igual que 2. |
| **Saldos sin corromperse** | Hay que rehacer el modelo, que hoy tiene 3 copias por solicitud y contadores mutables. | Se diseña desde cero como **libro de movimientos** (ver la §2.3). | Igual que 2. |
| **Estilo final** | Hay que reestilizar cada componente: Tailwind índigo → la nueva guía. Más de 14 000 líneas. | Ya cerca del estilo final. | Hay que reestilizar los componentes de turnos que se traigan. |
| **Hosting** | DreamHost + Firebase (dos proveedores, y los datos del equipo en Google). | Solo DreamHost. | Solo DreamHost, pero con Node en GitHub Actions para compilar. |
| **Mantenimiento** | Componentes de 1000 a 2000 líneas, sin tests, con errores silenciados. | Código pequeño con tests. Crecerá con el calendario de turnos. | Dos lenguajes y un paso de compilación. Los componentes de turnos de la anterior igual hay que reescribirlos para sacarles Firebase (llaman a la base desde dentro de la interfaz). |
| **Riesgo principal** | Rehacer seguridad y modelo es casi reescribirla, con más deuda. | La **interfaz del calendario de turnos** en JavaScript sin framework es la pieza más grande y nueva. | Complejidad de herramientas para un equipo de una persona. |

### 2.2 Recomendación: camino 2

- Lo difícil de verdad (cupo concurrente, reglas, tests y seguridad del servidor) **ya está bien resuelto en la app nueva**. En la anterior habría que rehacerlo, y además pagando Firebase.
- La app anterior **no tiene datos reales**, así que no hay nada que preservar en Firebase.
- Su lógica de turnos y horas extra es **lógica pura** (`utils/extraHours.ts`, 164 líneas) que se porta a PHP **con tests**, y la interfaz se rehace con la guía visual nueva. Habría que reestilizarla igual en cualquier camino.
- **Mitigación del riesgo principal:** el calendario de turnos se construye en módulos separados (`turnos.js`, `horas-extra.js`). Si a mitad de camino la interfaz se vuelve inmanejable sin framework, se puede sumar una librería pequeña cargada como módulo, sin paso de compilación. Lo evaluaríamos en un punto de control del plan.

**Tecnología que se mantiene:** PHP 8.1+, SQLite (WAL), HTML, CSS y JavaScript sin compilar, y DreamHost. **Se agrega** el despliegue automático por SFTP desde GitHub Actions, tomado de la app anterior y adaptado para que **nunca** suba ni sobrescriba `config.php` ni la carpeta de datos.

### 2.3 Cambios de modelo que exige el alcance "Todo"

- **Credenciales y sesiones:** PIN (hash), código de activación (hash con vencimiento), rol, bloqueo por intentos, y sesiones guardadas como **hash del token**, con dispositivo, vencimiento y revocación.
- **Tipos de ausentismo configurables:** tabla `tipos_ausentismo` con: nombre y sigla; si consume cupo y cómo; si descuenta saldo y de cuál; si admite medio día; quién lo registra (el funcionario o solo coordinación); si exige reemplazo; cómo se muestra a los demás (p. ej. licencia → "Ausente", sin el tipo); y si está activo. FL, ADM, CT y Licencia vienen precargados con las reglas del SPEC.
- **Cupo por bloque:** cada día de solicitud ocupa `am` y/o `pm`. El cupo se compara por bloque.
- **Saldos como libro de movimientos**, no como contador:
  - saldo = saldo inicial del período + ajustes de coordinación (con motivo) − días de solicitudes **autorizadas** que descuentan;
  - **se calcula, no se guarda**, así que es imposible el doble descuento (bug B5);
  - se cuentan **días hábiles** (corrige B7).
- **Calendario de turnos:** tabla `turnos` (fecha, largo o noche, unidad, titular, quien lo cubre, si es extra, horas). Una ausencia autorizada de un turnante marca su turno como "cubierto por X". Un cambio de turno autorizado intercambia los titulares. Todo dentro de la **misma transacción** que la resolución (corrige B6 y B15).
- **Horas extra:** se calculan en el servidor con las reglas de `extraHours.ts`, más una corrección manual por turno. Los feriados vienen de la tabla editable, no de una lista fija en el código.

### 2.4 Qué se descarta de cada app

**De la app anterior se descarta:**
- Firebase completo: Auth, Firestore, reglas, el proyecto `administrador-de-turnos-hpm` y la copia en `localStorage`.
- Tailwind por CDN y la paleta índigo.
- El guardado de cada solicitud en 3 lugares y la clasificación del tipo por texto libre.
- Los contadores de saldo mutables.
- "Cancelar" que borra, y "Eliminar solicitud" de coordinación (se reemplazan por **anular**, que conserva la historia).
- El formulario de registro con selector de rol, y los roles aplicados solo en la interfaz.
- Los feriados fijos en el código, el PDF con la etiqueta "(Licencia)" y el portal público con los RUT de todos.
- Los datos de perfil innecesarios: edad, foto y borrar la propia cuenta. **Pregunta 12**: el teléfono podría quedarse para coordinar reemplazos.
- Los errores silenciados (`catch {}`).

**De la app anterior se conserva** (reescrito): calendario de turnos por unidad, reemplazos elegidos de la nómina por turno, cambio de turno con devolución que intercambia turnos, turnos y horas extra, saldos (con el modelo nuevo), licencias, dashboard "hoy", "personal libre" para buscar reemplazos, vista del registro de cambios, PDF o impresión del calendario de turnos, y despliegue automático.

**De la app nueva se descarta:**
- La clave compartida de coordinación y `coord_sin_clave`.
- "Resuelto por" en texto libre (será la persona que inició sesión).
- La anulación con RUT.
- La búsqueda pública de nombres y de "Mis solicitudes".
- Las solicitudes "fuera de nómina" (ver la §3).
- El límite de envíos **por IP** como mecanismo principal (pasa a ser por cuenta).
- La exposición de la calidad jurídica en la simulación (N2).
- Los restos del estilo Gerson 1.

**De la app nueva se conserva:** todo el motor de reglas y su transacción, los tests, la nómina por CSV, feriados, exportación CSV, resumen, respaldo, auditoría, código `AUS-…`, estados, anulación con plazo, "vencidas", CSP, CSRF y `.htaccess`.

### 2.5 Datos

No hay migración desde Firebase. El "paso de migración" del plan será:
1. **Carga inicial de la nómina** desde `nomina-hpm.csv`, con un script repetible, `--dry-run`, sin duplicar y sin modificar el CSV. Habrá que completar la calidad jurídica si falta.
2. **Carga de saldos iniciales**, si me dices de dónde salen (pregunta 4).
3. **Generación de códigos de activación** para todos.

---

## 3. Qué partes de `SPEC.MD.md` cambian

| Sección del SPEC | Hoy dice | Cambia a | Motivo |
|---|---|---|---|
| §1 Propósito / no-objetivos | Sin cuentas, sin saldos, sin licencias. | Cuentas para todos (opción A). **Saldos internos** que se descuentan al autorizar. **Licencias** registradas por coordinación. Tipos ampliables. | Login decidido y respuestas de la §8. Sigue sin reemplazar a RR.HH.: el saldo es de referencia interna. |
| §1 Anti-objetivos | "Registrarse, verificar algo…" es un fracaso. | Se permite **una activación única** por persona (unos 40 s), sin correo. Pedir un día no requiere ningún paso de identificación. | Login. |
| §3.2 Fuera de nómina | Cualquiera pide aunque no esté en la nómina. | **Se elimina.** Si alguien no está, coordinación lo agrega y le da un código. La alternativa es que coordinación registre la solicitud en su nombre. | Sin cuenta no hay identidad. |
| §3.1 Equipos | 17. | **19** (+ Oncología infantil y Neurología infantil). | Respuesta. |
| §4.1 Tipos | Lista cerrada FL / ADM / CT. | FL, ADM, CT y **Licencia** precargados, más tipos creados por jefatura, cada uno con sus reglas configuradas. | Respuesta. |
| §4.2 Medio día | ADM ½ = 0,5 contra un cupo diario. | **Cupo por bloque**: AM y PM cada uno con el cupo del equipo; día completo = 1 en ambos bloques. | Respuesta. |
| §4.3 Turnantes | El sistema no valida contra un calendario de turnos; reemplazo en texto libre. | Existe el **calendario de turnos**. El reemplazo se elige de la nómina. Al autorizar, el turno queda "cubierto por". **Pregunta 2**: ¿se exige que la persona tenga turno ese día? | Alcance "Todo". |
| §4.4 Cambio de turno | Solo se registra. | Al autorizar, **intercambia los turnos** (y la devolución). **Pregunta 3**: ¿la contraparte debe aceptar en la app? | Alcance "Todo". |
| §4.5 Anulación | Con RUT, hasta las 23:59 del día anterior. | **Solo la persona dueña de la solicitud** (con su sesión) o coordinación. Mismo plazo. Anular una autorizada devuelve el saldo y restaura los turnos. | Login. |
| §5.1 Vista pública | Calendario, formulario y "Mis solicitudes" sin clave. Búsqueda pública de nombres. | **Todo detrás del login.** El formulario ya no pide nombre. "Mis solicitudes" muestra solo las propias. **Pregunta 7**: ¿alguna vista sin login? | Login: se elimina el riesgo de pedir a nombre de otro y de ver solicitudes ajenas. |
| §5.1 Anti-abuso | Honeypot y 10 envíos por IP por hora. | Límite **por cuenta**. Se quita el honeypot del formulario autenticado. | Login, y el problema de la IP compartida (N3). |
| §5.2 Coordinación | Clave compartida, sesión de 8 h, "Resuelto por" en texto libre, bloqueo por IP. | **Roles por persona.** La resolución queda firmada por quien inició sesión. Bloqueo por cuenta. | Login. |
| §5.2 (nuevas secciones) | — | Calendario de turnos, turnos extra y horas extra, saldos (ver, ajustar con motivo), tipos de ausentismo, licencias, códigos de activación, sesiones activas (revocar). | Alcance "Todo". |
| §7 Riesgo aceptado | Cualquiera puede pedir a nombre de otro. | **Eliminado.** Solo coordinación registra en nombre de otro, y queda auditado. | Login. |
| §8 Diseño | Gerson 1. | Guía visual nueva, a partir de la referencia que enviarás. | Encargo. |
| §9 Tests | Los actuales. | Más: login (activación, PIN, bloqueo por cuenta, expiración y revocación de sesión, acceso por rol), cupo por bloque, saldos (libro de movimientos, sin doble descuento, días hábiles), turnos (reemplazo e intercambio dentro de la transacción) y horas extra (casos de `extraHours.ts`). | — |
| §12 Decisiones por defecto | "Resuelto por" en texto libre, anulación con RUT. | Se reemplazan por las de esta propuesta. | — |

---

## 4. Preguntas que solo tú puedes responder

Ordenadas de la más importante a la menos importante.

1. **Login:** ¿apruebas la **opción A** (RUT + PIN de 6 dígitos, activación con código entregado por coordinación, sesión de 180 días en el celular y modo "computador compartido")?
2. **Turnantes y calendario de turnos:** al pedir un día, ¿el sistema debe **exigir** que la persona tenga turno cargado ese día (como la app anterior)? ¿O basta con lo que declare (como el SPEC)? Lo primero exige que coordinación mantenga el calendario al día.
3. **Cambio de turno:** ¿la persona con quien se cambia debe **aceptar en la app** antes de que coordinación lo vea? ¿O basta con que coordinación lo autorice?
4. **Saldos:**
   - ¿Qué saldos se llevan (FL, ADM, otros)?
   - ¿De dónde salen los **saldos iniciales** (los ingresa coordinación, o un CSV de RR.HH.)?
   - ¿El período es anual (enero a diciembre) o por fecha de ingreso?
   - ¿Se arrastra lo no usado?
   - Para un **turnante**, ¿un FL descuenta por turno o por día?
   - ¿Se puede pedir con saldo insuficiente (con aviso) o se bloquea?
5. **Licencias:** ¿las registra **solo coordinación**? ¿Qué ven los demás en el calendario: "Ausente", "Licencia" o nada? ¿Una licencia consume cupo del equipo?
6. **Tipos nuevos:** cuando jefatura cree un tipo, ¿basta con estas opciones: consume cupo sí/no, descuenta saldo sí/no y de cuál, medio día sí/no, quién lo pide, exige reemplazo, cómo se muestra? ¿Falta alguna?
7. **¿Alguna vista sin login?** Por ejemplo, un calendario de solo lectura para una pantalla en la sala. Si no, todo queda detrás del login.
8. **Roles:** ¿coordinación ve y resuelve **todos** los equipos, o cada coordinador solo los suyos? ¿Quiénes serán jefatura?
9. **Horas extra:** ¿siguen vigentes las reglas de la app anterior?
   - turno largo de 12 h (11 h en festivo);
   - noche de 12 h, o 13 h si el día siguiente es festivo;
   - un diurno que cubre un turno largo en día hábil suma 3 h extra (4 h el viernes);
   - medio día AM sin horas extra.

   ¿Qué se hace con ellas? ¿Solo verlas, o exportarlas para pagarlas?
10. **Recuperación de acceso:** ¿quién puede generar códigos nuevos: coordinación o solo jefatura? ¿Basta con WhatsApp o en persona para entregarlos?
11. **Primera activación:** ¿prefieres entregar los códigos en una hoja impresa por equipo o uno por uno por WhatsApp?
12. **Teléfono:** ¿se guarda el teléfono de cada funcionario (para coordinar reemplazos) o no?
13. **Firebase:** ¿apago o borro el proyecto `administrador-de-turnos-hpm` y el sitio estático de la app anterior en DreamHost? No lo haré sin tu confirmación explícita.
14. **Referencia visual:** quedo esperando la imagen para la Fase 3.

Espero tu decisión antes de pasar a la Fase 3 (SPEC v2 y plan).
