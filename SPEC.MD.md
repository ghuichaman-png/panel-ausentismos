# Prompt para Claude Code — Panel de Ausentismos · Kinesiología HPM

> **Cómo usar este documento:** pégalo completo como primer mensaje en Claude Code (o guárdalo en la raíz del repositorio como `SPEC.md` y pídele "implementa SPEC.md"). El diseño ya fue conversado y aprobado: Claude Code no necesita repetir la ronda de preguntas de requisitos, solo planificar e implementar. Si encuentra una contradicción real en este documento, debe preguntar antes de suponer.

---

## 1. Contexto y propósito

Soy Jefe Técnico de un equipo de ~70 kinesiólogos del Hospital Puerto Montt (HPM), en aumento. Unos 25 son **turnantes** (cuarto turno: turno largo y turno de noche) y el resto son **diurnos**. Necesito una aplicación web donde los funcionarios **soliciten sus días de ausentismo**, vean en un **calendario** qué días tienen cupo, y consulten si su solicitud fue **autorizada o no**. Un equipo de **coordinación** revisa y resuelve las solicitudes.

Es una herramienta de **gestión interna del equipo**. **No reemplaza** el sistema institucional de RR.HH.: los saldos de feriado y administrativos se siguen llevando allá. Aquí solo se coordina quién falta y cuándo, para no dejar servicios sin cobertura.

**Lo que define el éxito:** que los funcionarios realmente lo usen. Pedir un día debe tomar menos de un minuto, desde el celular, sin crear cuentas ni recordar claves. Cada paso extra en el formulario del funcionario reduce la adherencia.

### Objetivos
- Calendario mensual que muestre la disponibilidad de cupo por equipo (diurnos) y las ausencias de turnantes.
- Formulario de solicitud de un solo paso, con autocompletado desde la nómina.
- Consulta pública del estado de cada solicitud (y del motivo si fue rechazada).
- Vista de coordinación protegida con clave compartida para autorizar, rechazar, administrar nómina, equipos y cupos, y exportar.

### No-objetivos (no construir)
- Cuentas individuales, login de funcionarios, recuperación de contraseña.
- Cálculo o control de **saldos** de feriado legal o administrativos.
- Licencias médicas (no se registran aquí).
- Integración con sistemas institucionales.
- Notificaciones por correo (fase 2; dejar el código preparado, ver §11).

### Anti-objetivos (sería un fracaso aunque "funcione")
- Que un funcionario tenga que registrarse, verificar algo o completar más de lo indispensable para pedir un día.
- Que cualquier persona sin la clave pueda autorizar, rechazar o modificar configuración.
- Que dos solicitudes simultáneas sobrepasen un cupo por condición de carrera.
- Que la base de datos sea descargable desde una URL pública.
- Que el calendario comunique disponibilidad solo con color (debe llevar siempre etiqueta textual).

---

## 2. Stack y restricciones de hosting

- **Hosting:** DreamHost, hosting compartido. Se sube por FTP/SFTP. Sin Docker, sin Node en producción, sin procesos en segundo plano.
- **Backend:** PHP 8.1+ puro, sin frameworks y **sin dependencias de Composer en producción** (si usas PHPUnit para tests, que sea solo en desarrollo y no se suba `vendor/`).
- **Base de datos:** **SQLite vía PDO** (`pdo_sqlite`, disponible en DreamHost). Un único archivo `.sqlite`.
  - El archivo debe vivir **fuera de la carpeta pública** del dominio (en DreamHost: `/home/USUARIO/ausentismos-data/` mientras la web vive en `/home/USUARIO/dominio.cl/ausentismos/`). La ruta se define en `config.php`.
  - Además, incluir `.htaccess` que deniegue acceso a cualquier `*.sqlite`, `config.php` y carpetas internas, como segunda barrera.
  - Activar `PRAGMA journal_mode=WAL`, `PRAGMA foreign_keys=ON` y `busy_timeout`.
  - **Toda validación de cupo + inserción debe ocurrir dentro de una transacción `BEGIN IMMEDIATE`** para que dos solicitudes simultáneas no puedan tomar el mismo cupo.
- **Frontend:** una sola página (HTML + CSS + JavaScript vanilla, sin build step) que consume una API JSON en PHP. Mobile-first; debe funcionar bien en celulares Android/iOS y en computadores del hospital (Chrome/Edge recientes).
- **Zona horaria:** `America/Santiago`. Idioma: español de Chile. Fechas visibles en formato `dd-mm-aaaa`; en la API, ISO `aaaa-mm-dd`.
- **Instalación:** un `install.php` (o script equivalente) que crea el esquema, siembra los equipos iniciales y luego se auto-deshabilita o exige borrarse. Un `README.md` con pasos de despliegue en DreamHost, ruta de datos, cambio de clave y respaldo.

---

## 3. Datos de dominio

### 3.1 Equipos / unidades (sembrar al instalar, editables por coordinación)

| Equipo | Tiene diurnos | Tiene turnantes | Cupo diurno/día (inicial) |
|---|---|---|---|
| H7 | sí | sí | 2 |
| H6 | sí | sí | 2 |
| UTI H5 | sí | sí | 2 |
| UTAC H5 | sí | no | 2 |
| H5 | sí | sí | 2 |
| H3 Pensionados | sí | no | 2 |
| Q3 Neonatología | sí | no | 2 |
| Q2 UTI Qx | no | sí | — |
| H8 | sí | sí | 2 |
| Traumatología | sí | no | 2 |
| Neurología | sí | no | 2 |
| Cardio adultos | sí | no | 2 |
| Cardio infantil | sí | no | 2 |
| Piso pélvico | sí | no | 2 |
| Piscina terapéutica | sí | no | 2 |
| Oncología | sí | no | 2 |
| Respiratorio | sí | no | 2 |

- UTI H5, UTAC H5 y H5 son **tres unidades distintas**. Q2 y Q3 son pisos separados.
- Cada equipo tiene: nombre, `tiene_diurnos`, `tiene_turnantes`, `cupo_diurno` (decimal, permite 0,5), `activo`, orden de despliegue.
- Las unidades con `tiene_turnantes = sí` son las opciones del campo "unidad del turno" en solicitudes de turnantes.

### 3.2 Nómina de funcionarios
Campos: `id`, `nombre` (completo), `rut` (normalizado sin puntos y con guion, validar dígito verificador), `jornada` (`diurno` | `turnante`), `equipo_id` (obligatorio para diurnos; opcional para turnantes porque rotan), `calidad` (`titular` | `contrata` | `honorarios`), `activo`.

- Coordinación carga la nómina por **CSV** (columnas: `nombre,rut,jornada,equipo,calidad`) y puede editarla una por una. La importación hace *upsert* por RUT y muestra un resumen (creados, actualizados, filas con error y por qué). Entregar `nomina-ejemplo.csv`.
- Un funcionario que no está en la nómina **igual puede solicitar** (ver §5.1): se guarda como solicitud "fuera de nómina" con los datos que ingresó, y coordinación puede vincularla a una persona existente o crearla en la nómina.

### 3.3 Feriados
Tabla de feriados (fecha, nombre) editable por coordinación. Sirve para no contar feriados como días hábiles de diurnos. No sembrar fechas inventadas: dejar la tabla vacía con una nota en la vista de coordinación para cargarlos (o permitir pegar una lista).

### 3.4 Solicitudes
Campos mínimos: `id`, `codigo` corto legible (ej. `AUS-2026-0142`), `funcionario_id` (nullable si fuera de nómina) + snapshot de `nombre`, `rut`, `jornada`, `equipo_id`, `calidad` al momento de pedir, `tipo`, `fecha_inicio`, `fecha_fin`, `fraccion` (`completo` | `am` | `pm` para diurnos; `completo` | `primera_mitad` | `segunda_mitad` para turnantes), `turno` (`largo` | `noche`, solo turnantes), `unidad_turno_id` (solo turnantes), `reemplazo_nombre` (turnantes), campos de cambio de turno (§4.4), `comentario` opcional, `sobre_cupo` (bool), `justificacion_sobre_cupo`, `estado`, `motivo_resolucion`, `resuelto_por` (texto), `resuelto_en`, `fuera_de_nomina` (bool), `creado_en`, `ip`, `user_agent`, `anulado_en`.

Tabla `solicitud_dias` (una fila por día afectado, con su consumo de cupo) para que el cálculo de ocupación sea una suma simple por equipo y fecha.

Tabla `auditoria` (fecha/hora, acción, solicitud_id, detalle, ip) para cada creación, resolución, anulación y cambio de configuración.

---

## 4. Reglas de negocio

### 4.1 Tipos de ausentismo
| Tipo | Diurnos | Turnantes |
|---|---|---|
| Feriado legal (FL) | sí, día(s) completos, rango de fechas | sí, por turno(s) |
| Permiso administrativo (ADM) | día completo, o medio día AM / PM | turno completo, o media jornada (primera o segunda mitad del turno) |
| Cambio de turno (CT) | **no disponible** | sí |

### 4.2 Cupo (solo aplica a DIURNOS)
- **Los turnantes nunca consumen ni bloquean cupo**, porque siempre dejan a alguien cubriendo su turno.
- Cada equipo con diurnos tiene un `cupo_diurno` por día (inicial **2**, configurable por equipo).
- Consumo por día afectado, para diurnos:
  - ADM día completo: **1**. ADM medio día (AM o PM): **0,5**.
  - FL de **menos de 5 días hábiles**: **1** por día hábil.
  - FL de **5 o más días hábiles** de un funcionario **titular o contrata**: **0** (no usa cupo porque ese reemplazo se gestiona aparte), pero **sí aparece en el calendario** como ausencia.
  - FL de funcionario **a honorarios**: **siempre 1** por día hábil, sin importar la duración (deben reemplazarse entre los existentes).
- Días hábiles de diurnos = lunes a viernes que no estén en la tabla de feriados. Un diurno no puede seleccionar sábados, domingos ni feriados como días de ausencia; si elige un rango que los incluye, se omiten automáticamente y se muestra cuántos días hábiles quedaron.
- **Ocupan cupo** las solicitudes en estado `pendiente` y `autorizada`. **Liberan cupo** las `rechazada` y `anulada`.
- Ocupación de un día para un equipo = suma del consumo de `solicitud_dias` activas de diurnos de ese equipo en esa fecha.
- **Sobre cupo:** si algún día de la solicitud quedaría sobre el cupo, el formulario lo advierte indicando qué días están llenos. El funcionario puede (a) cambiar las fechas o (b) enviarla igualmente como **"solicitud sobre cupo"**, con **justificación obligatoria**. Esas solicitudes se marcan visiblemente para coordinación, que puede autorizar un tercero en casos justificados.
- La verificación de cupo se hace **en el servidor, dentro de la transacción**, nunca solo en el cliente. Si entre que el funcionario abrió el formulario y lo envió alguien tomó el último cupo, el servidor responde con los días que ya no tienen cupo y el formulario ofrece la opción de sobre cupo.

### 4.3 Turnantes (cuarto turno)
- Cada solicitud de turnante indica, por cada fecha: **turno** (`largo` o `noche`; la noche se registra con la fecha en que comienza), **unidad que cubre ese turno** (lista de unidades con turnantes) y **quién lo cubre** (`reemplazo_nombre`, obligatorio, con autocompletado desde la nómina pero aceptando texto libre).
- El turnante decide qué días le corresponde turno; el sistema **no** valida contra un calendario de turnos.
- No hay cupo ni bloqueo para turnantes, pero coordinación igual autoriza o rechaza.

### 4.4 Cambio de turno (solo turnantes)
- Campos: fecha y turno que cede, unidad, **con quién cambia** (autocompletado de nómina, obligatorio), y opcionalmente fecha y turno de devolución.
- No consume cupo, sin límite diario. Requiere autorización de coordinación.

### 4.5 Estados y flujo
```
pendiente ──(coordinación autoriza)──> autorizada
pendiente ──(coordinación rechaza + motivo obligatorio)──> rechazada
pendiente | autorizada ──(funcionario anula, hasta las 23:59 del día anterior a la primera fecha)──> anulada
```
- La solicitud se resuelve **completa** (no se autorizan días sueltos de un rango). Si coordinación quiere autorizar solo una parte, rechaza con motivo y el funcionario pide de nuevo.
- Coordinación puede revertir una resolución (autorizada ↔ rechazada) dejando registro en auditoría; al pasar a autorizada se re-verifica el cupo y, si no hay, exige marcarla como sobre cupo con justificación.
- **Anulación por el funcionario:** para evitar que alguien anule la solicitud de otra persona sin tener cuentas, se pide el **RUT** de quien solicitó; debe coincidir con el RUT de la solicitud. Coordinación puede anular sin RUT.
- No se permite que una misma persona tenga dos solicitudes activas (pendiente/autorizada) que se superpongan en la misma fecha (para turnantes: misma fecha y mismo turno).
- No se puede solicitar con fecha pasada. (Sin anticipación mínima ni horizonte máximo por ahora, pero dejar ambos como parámetros en `config.php`, desactivados.)
- Solicitudes `pendiente` cuya fecha ya pasó se destacan en coordinación como "vencida sin resolver".

---

## 5. Pantallas

### 5.1 Vista pública (funcionarios) — sin clave
**a) Calendario mensual (pantalla principal)**
- Navegación por mes (anterior / siguiente / hoy).
- Selector de vista: **un equipo de diurnos** (por defecto el último usado, guardado en `localStorage` con try/catch), **"Todos los equipos"** o **"Turnantes"**.
- Vista por equipo: cada día hábil muestra ocupación con **color semántico + texto**:
  - `Libre` (0 ocupado) → `normal` / `normal-fondo`
  - `1 de 2` (o el valor que corresponda, incluye 0,5) → `precaucion` / `precaucion-fondo`
  - `Lleno` (≥ cupo) → `critico` / `critico-fondo`
  - Fines de semana y feriados en gris neutro, no seleccionables.
  - Pendientes y autorizadas se distinguen (p. ej. pendiente con borde punteado y la palabra "pendiente").
- Vista "Todos los equipos": cada día muestra cuántos equipos están llenos y el total de ausentes; al tocar un día se ve el detalle por equipo.
- Vista "Turnantes": por día, lista de ausencias de turnantes con turno, unidad y reemplazo.
- Al tocar un día: detalle con **nombre, tipo (FL / ADM / ADM ½ AM/PM / CT) y estado**. No mostrar RUT, calidad jurídica, IP ni justificaciones a otros funcionarios.
- Botón principal muy visible: **"Pedir días"**. Si se toca un día disponible en el calendario, el formulario se abre con esa fecha precargada.

**b) Formulario "Pedir días" (un solo paso)**
1. **Nombre:** campo con autocompletado sobre la nómina (búsqueda insensible a tildes y mayúsculas). Al elegir, se precargan y muestran en modo lectura jornada, equipo y calidad. Si la persona no aparece: botón "No estoy en la lista" que despliega RUT, jornada, equipo y calidad (los mínimos para aplicar las reglas).
2. **Tipo:** según jornada (CT solo para turnantes).
3. **Fechas:** día único o rango (FL). Para ADM medio día: AM/PM (diurno) o primera/segunda mitad (turnante).
4. Si es **turnante**: turno, unidad del turno y quién lo cubre (por fecha si el rango tiene varias).
5. Si es **CT**: con quién cambia y devolución opcional.
6. Comentario opcional.
7. Resumen en vivo antes de enviar: días hábiles afectados, consumo de cupo y advertencia si algún día queda lleno (con opción de sobre cupo + justificación).
- Al enviar: pantalla de confirmación con el **código de solicitud**, estado "Pendiente" y recordatorio de que puede consultar el estado en "Mis solicitudes".
- Protección mínima anti-abuso sin fricción: campo *honeypot* oculto y límite de envíos por IP (p. ej. 10 por hora). Nada de captcha.

**c) "Mis solicitudes"**
- Buscar por nombre (mismo autocompletado) o por código. Lista con fechas, tipo, estado y, si fue rechazada, **el motivo**.
- Botón "Anular" (visible hasta las 23:59 del día anterior), que pide el RUT para confirmar.

### 5.2 Vista de coordinación — `/coordinacion` (clave compartida)
- Acceso con **una clave compartida** del equipo de coordinación, guardada como `password_hash` en `config.php` (nunca en texto plano). Tras ingresarla se usa una cookie de sesión PHP (`HttpOnly`, `Secure`, `SameSite=Strict`) que expira tras 8 horas de inactividad. Es la **única** parte del sistema con sesión. Bloqueo temporal tras 5 intentos fallidos por IP. Todas las acciones con token CSRF.
- Al resolver se pide un campo **"Resuelto por"** (iniciales o nombre, texto libre, recordado en `localStorage`) para trazabilidad, ya que la clave es compartida.

**Secciones:**
1. **Bandeja de pendientes:** ordenada por fecha de ausencia más próxima. Filtros por equipo, jornada, tipo, "sobre cupo", "fuera de nómina", "vencidas". Cada fila muestra ocupación del equipo en esos días. Acciones: Autorizar / Rechazar (motivo obligatorio). Ver IP y hora de creación.
2. **Todas las solicitudes:** búsqueda y filtros por rango de fechas, estado, equipo, persona. Revertir resolución, anular.
3. **Calendario de coordinación:** igual al público, pero con todos los datos (incluye justificaciones y sobrecupos).
4. **Resumen por persona:** por año, cantidad de días por tipo (FL, ADM en días con 0,5, CT) y por estado. Es informativo, no es saldo.
5. **Nómina:** importar CSV, editar, desactivar, vincular solicitudes "fuera de nómina" a una persona.
6. **Equipos y cupos:** editar nombre, banderas diurnos/turnantes, cupo diurno, activo, orden.
7. **Feriados:** agregar/eliminar fechas.
8. **Exportar:** CSV (separador `;`, UTF-8 con BOM para que Excel en español lo abra bien) de solicitudes filtradas y del resumen por persona.
9. **Respaldo:** botón para descargar una copia del `.sqlite` (usando la API de backup de SQLite o `VACUUM INTO`, no copiando el archivo en uso).

---

## 6. API (sugerida; ajústala si encuentras algo mejor y documenta el cambio)
Todas las respuestas en JSON; errores con código HTTP adecuado y mensaje en español.

Públicas:
- `GET  api/config` → equipos activos, tipos, parámetros visibles.
- `GET  api/nomina/buscar?q=` → coincidencias (solo id, nombre, jornada, equipo; **sin RUT**).
- `GET  api/calendario?mes=2026-12&vista=equipo&equipo_id=3` → ocupación y ausencias del mes.
- `POST api/solicitudes` → crear (validación completa en servidor, transacción `BEGIN IMMEDIATE`).
- `GET  api/solicitudes?funcionario_id=` o `?codigo=` → listado público reducido.
- `POST api/solicitudes/{id}/anular` → requiere RUT.

Coordinación (requieren sesión + CSRF):
- `POST api/coord/login`, `POST api/coord/logout`
- `GET  api/coord/solicitudes?...`
- `POST api/coord/solicitudes/{id}/resolver` (autorizar | rechazar, motivo, resuelto_por)
- `POST api/coord/solicitudes/{id}/revertir`
- CRUD `api/coord/nomina`, `POST api/coord/nomina/importar`
- CRUD `api/coord/equipos`, CRUD `api/coord/feriados`
- `GET  api/coord/resumen?anio=`
- `GET  api/coord/exportar?...`, `GET api/coord/respaldo`

Si DreamHost no tiene reescritura de URL disponible para la carpeta, usa `api.php?r=...` como alternativa.

---

## 7. Seguridad y privacidad
- Consultas siempre con sentencias preparadas. Escapar toda salida en el frontend (usar `textContent`, nunca `innerHTML` con datos de usuario).
- La vista pública **nunca** expone RUT, calidad jurídica, IP, user agent ni justificaciones de sobre cupo.
- Encabezados: `Content-Security-Policy` razonable, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.
- `.htaccess` que bloquee acceso directo a `config.php`, `lib/`, `data/`, `*.sqlite`, `install.php` tras instalar.
- Forzar HTTPS (DreamHost entrega Let's Encrypt gratis).
- Riesgo **aceptado** para el piloto: como no hay cuentas, cualquiera podría crear una solicitud a nombre de otra persona. Mitigación: IP + user agent + hora en cada solicitud, visibles en coordinación, y anulación protegida por RUT.

---

## 8. Diseño visual — sistema "Gerson 1"
Usar estos tokens como variables CSS (`:root`). Tema por defecto **crema** en pantalla; hoja de estilos de **impresión** con el tema **blanco** (para imprimir el calendario del mes).

**Color (tema crema / tema blanco para impresión):**
| Token | Crema | Blanco | Uso |
|---|---|---|---|
| `--fondo` | `#fdf6e3` | `#ffffff` | fondo general |
| `--fondo-alt` | `#f6ebcf` | `#f4f5f2` | tarjetas, filas alternas |
| `--tinta` | `#2b3a39` | `#2b3a39` | texto principal |
| `--tinta-suave` | `#5e5650` | `#5e5650` | texto secundario, metadatos |
| `--linea` | `#dccfb2` | `#ddddd6` | bordes y separadores (nunca texto) |
| `--petroleo` | `#2b3a39` | `#2b3a39` | encabezado/barra superior |
| `--marca` | `#416b66` | `#416b66` | títulos de sección, íconos, botón principal |
| `--marca-vivo` | `#4aba94` | `#4aba94` | solo decorativo, **nunca texto** |
| `--acento` | `#d35e36` | `#d35e36` | un único elemento destacado por pantalla (p. ej. día de hoy) |
| `--acento-texto` | `#a34427` | `#a34427` | texto pequeño acentuado |
| `--arena` | `#f1d49a` | `#f1d49a` | resaltado de fondo |
| `--sobre-color` | `#fdf6e3` | `#fdf6e3` | texto sobre petróleo/marca |
| `--critico` / `--critico-fondo` | `#9b1c31` / `#f9e1e4` | igual | día lleno, errores |
| `--precaucion` / `--precaucion-fondo` | `#7a5200` / `#fbefd0` | igual | cupo parcialmente ocupado, sobre cupo |
| `--normal` / `--normal-fondo` | `#1e6b52` / `#ddf1e9` | igual | día libre, autorizada |
| `--info` / `--info-fondo` | `#285e6e` / `#dcebf0` | igual | pendiente, avisos informativos |

Reglas del sistema: la terracota (`acento`) **nunca** significa riesgo; el riesgo usa la paleta semántica y **siempre lleva etiqueta textual** (debe entenderse en blanco y negro o con daltonismo). Todo texto cumple contraste 4,5:1. Sin sombras ni degradados: la jerarquía la dan el color y el espacio.

**Tipografía (Google Fonts, con fallbacks del sistema por si la red del hospital las bloquea):**
- Títulos y cifras: `"Outfit", "Avenir Next", "Segoe UI", system-ui, sans-serif` (peso 600).
- Texto: `"DM Sans", "Segoe UI", system-ui, sans-serif`, con cifras tabulares en tablas y calendario.
- Escala para web: título de página 28–32 px, subtítulo 20 px, cuerpo 15–16 px, nota 13 px, etiqueta 12 px en mayúsculas con `letter-spacing: .08em`.

**Espaciado y forma:** escala de 4 px (4, 8, 16, 24, 40, 64). Radios: 4 px etiquetas, 10 px tarjetas y celdas, 24 px bloques grandes, 999 px chips.

**Marca:** encabezado en `--petroleo` con el título configurable en `config.php` (por defecto "Ausentismos · Kinesiología HPM"). Sin logo personal por defecto; dejar en `config.php` una opción para mostrar un logo si se sube el archivo.

**Accesibilidad:** objetivos táctiles ≥ 44 px, navegación por teclado completa, foco visible, etiquetas de formulario reales, calendario usable con lector de pantalla (cada celda con `aria-label` tipo "martes 9 de diciembre, 1 de 2 cupos ocupados").

---

## 9. Verificación (obligatoria antes de dar por terminado)
1. **Tests automatizados de reglas** (script PHP de pruebas sin dependencias, o PHPUnit solo en desarrollo), cubriendo al menos:
   - Diurno ADM completo en equipo con cupo 2 → ocupa 1; tercero sin sobre cupo es rechazado por el servidor; con justificación queda `sobre_cupo = true`.
   - Dos ADM medio día (AM + PM) suman 1.
   - FL de 4 días hábiles de titular → consume 1 por día.
   - FL de 5 días hábiles de titular o contrata → consume 0 pero aparece en el calendario.
   - FL de 10 días de honorarios → consume 1 por día hábil.
   - Rango que cruza fin de semana y un feriado → esos días no se cuentan ni se registran.
   - Turnante con FL, ADM o CT → nunca consume cupo; reemplazo obligatorio.
   - Rechazo y anulación liberan cupo; pasar de rechazada a autorizada re-verifica cupo.
   - Anulación con RUT incorrecto falla; después de las 23:59 del día anterior falla.
   - Solicitudes superpuestas de la misma persona se rechazan.
   - **Concurrencia:** dos procesos que piden el último cupo al mismo tiempo → solo uno lo obtiene sin sobre cupo.
2. **Datos de demostración** (`seed-demo.php`, opcional y separado de la instalación): ~70 funcionarios ficticios con nombres inventados y RUT válidos ficticios, y solicitudes variadas en el mes actual.
3. Probar la interfaz en un viewport de celular (375 px) y de escritorio, y revisar visualmente el calendario en los tres estados de cupo.
4. Probar que `config.php`, el `.sqlite` e `install.php` no son accesibles desde el navegador.

---

## 10. Estructura sugerida
```
public/                 → lo que se sube a /home/USUARIO/dominio.cl/ausentismos/
  index.html            → app pública (SPA)
  coordinacion/index.html
  assets/app.css, app.js, coord.js
  api.php               → enrutador de la API
  .htaccess
lib/                    → fuera de public si es posible (db.php, reglas.php, auth.php…)
config.example.php      → copiar a config.php (ruta de datos, hash de clave, título, parámetros)
install.php
seed-demo.php
tests/
nomina-ejemplo.csv
README.md
```

---

## 11. Preparado para después (no implementar ahora, pero no cerrar la puerta)
- Notificaciones por correo (a coordinación al crear, al funcionario al resolver): dejar una función `notificar()` vacía llamada en esos puntos.
- Bloqueo de días específicos o cupos distintos por fecha (campañas de invierno, acreditación).
- Plazos de anticipación mínima y horizonte máximo (ya en `config.php`, desactivados).
- Regla de feriado legal largo por calidad jurídica más fina (aún en definición).

---

## 12. Decisiones tomadas por defecto (revisables)
Estas no las definió explícitamente el usuario; se tomaron para que el sistema funcione y se pueden cambiar:
1. El umbral de "feriado legal largo" (5 días) se cuenta en **días hábiles** de la solicitud.
2. Las solicitudes se autorizan o rechazan **completas**, no por días.
3. Un diurno no puede pedir fines de semana ni feriados.
4. Los turnantes pueden no tener equipo fijo en la nómina; la unidad se indica en cada solicitud.
5. La anulación por el funcionario exige su RUT.
6. Sin logo personal por defecto; título institucional configurable.
7. "Resuelto por" en texto libre para trazabilidad con clave compartida.
