# Verificación final (SPEC-v2 §9, plan paso 7.3)

Fecha: 20-11-2026 · rama `unificacion/fase1-analisis` · `php tests/run.php` → **TODO OK (464 verificaciones, 107 pruebas)**. La salida completa va al final.

## 1. Pruebas automatizadas: dónde está cada punto de §9

| Punto de §9 | Prueba (archivo · nombre) |
|---|---|
| Concurrencia: el último cupo de un bloque, solo uno lo obtiene | 02 · «dos procesos piden el último cupo»; 12 · «cuatro procesos piden la última mañana» |
| Cupo 2: el 3.º AM queda sin cupo | 12 · «el tercer medio día de la MAÑANA queda sin cupo» |
| AM + PM de dos personas: cada bloque en 1 | 01 · «Dos ADM medio día (AM + PM)» |
| Día completo ocupa ambos bloques | 12 · «un día completo ocupa mañana y tarde» |
| FL ≥ 5 de titular consume 0; FL de honorarios consume 1 | 01 · FL de 5 días / FL de honorarios; 12 · «FL ≥ 5 hábiles… FL de honorarios ocupa ambos» |
| FL de lunes a domingo descuenta 5 | 13 · «Bug B7… descuenta 5 días hábiles, no 7» |
| ADM ½ descuenta 0,5; la pendiente reserva | 13 · «ADM medio día descuenta 0,5; las pendientes reservan saldo» |
| Saldo insuficiente bloquea; dos simultáneas no lo superan | 13 · «Bloqueo: si no alcanza…»; 13 · «Concurrencia de saldo» |
| Autorizar → rechazar → autorizar no descuenta dos veces; anular devuelve | 13 · «Bug B5…» |
| Ajuste con motivo suma | 13 · «Ajustes: suman o restan con motivo obligatorio» |
| Asignación anual 15/6 no se duplica | 13 · «Asignación anual…»; 21 · carga inicial repetida |
| FL que cruza de año | 13 · «FL que cruza de año» |
| Turnante sin turno cargado no puede pedir | 17 · «Se exige turno cargado» |
| Autorizar marca cubierto_por y es_extra | 18 · «el turno queda cubierto por el reemplazo, como extra» |
| Si algo falla, nada queda a medias | 15 · «Bug B4»; 18 · «Bug B6»; 04 · migración que falla se revierte |
| Anular restaura solo los turnos de esa solicitud | 18 · «Bug B15» |
| CT intercambia titulares, con y sin devolución | 18 · «Cambio de turno autorizado» |
| Licencia deja los turnos sin cubrir | 18 · «Licencia de un turnante…»; 20 · vista Hoy |
| Horas extra: cada fila de §4.7, corrección manual y medio día | 19 · las cuatro pruebas |
| Login: código válido, vencido, ya usado | 07 · «Activar…» (dos pruebas) |
| PIN trivial rechazado | 07 · «PIN: 6 dígitos y no trivial» |
| 5 errores bloquean la cuenta, no la IP | 07 · «Bloqueo por cuenta»; 08 · «límite por IP alto» |
| Sesión de 180 días se renueva; compartida vence a los 30 min | 07 · «Sesión personal»; 07 · «computador compartido» |
| Sesión revocada no sirve; código nuevo invalida el PIN | 07 · «Sesiones: se listan, se cierran…» |
| Sin sesión → 401; funcionario en coord/* → 403; CSRF obligatorio | 08 · tres pruebas HTTP |
| Un funcionario no anula la solicitud de otro | 01 · «Anulación: otra persona no puede»; 10 · «Anular: solo la dueña» |
| Privacidad: nómina/buscar y calendario sin RUT, calidad, teléfono ni justificaciones | 06 · «Privacidad…» (**ampliada en esta etapa**: antes solo revisaba la búsqueda) |
| La simulación no expone a otras personas | 03 · «Bug N2»; 10 · «Bug N2 por HTTP» |
| Un test por cada bug corregido, escrito antes | 03 (N1, N2); 06 (mensajes del CSV); 13 (B5, B7); 15 (B4); 18 (B6, B15) |

## 2. Carga inicial
21 · tres pruebas: `--dry-run` no escribe ni deja respaldo; repetir no duplica ni cambia nada; nunca borra; el hash del CSV no cambia; los códigos van a un archivo fuera de la app. Además se probó con una **copia** de `nomina-hpm.csv` en una base desechable: 63 personas, 0 errores, segunda pasada «sin cambios: 63», hash del original igual.

## 3. Interfaz (copia local con datos ficticios)
- 375 px, claro: ingreso con PIN; formulario de turnante (lista sus turnos y pide quién cubre); Pedir días con resumen de saldo; Mi perfil con saldos.
- 375 px, oscuro: calendario con los tres estados por bloque (libre, «1 de 2», «Mañana llena»); coordinación (Hoy, Saldos, Tipos, Registro).
- Escritorio 1280 px, claro: calendario y pestañas arriba.
- Sin errores de JavaScript. El único 422 visto es la validación esperada del turnante antes de elegir reemplazo.

## 4. En DreamHost (pendiente, se hace al publicar)
Lista de comprobación en el README («Comprobaciones al publicar»): en local no se puede probar `.htaccess` de Apache.

## Salida completa de `php tests/run.php`

```

── 01_reglas
• RUT: dígito verificador y normalización
    ✓ ok
• Diurno ADM completo, cupo 2: ocupa 1; el tercero es rechazado; con justificación queda sobre_cupo
    ✓ ok
• Dos ADM medio día (AM + PM): cada uno ocupa 1 en su bloque (SPEC-v2 §4.2; antes «suman 1»)
    ✓ ok
• FL de 4 días hábiles de titular consume 1 por día
    ✓ ok
• FL de 5 días hábiles de titular/contrata consume 0 pero aparece en el calendario
    ✓ ok
• FL de 10 días hábiles de honorarios consume 1 por día
    ✓ ok
• Rango que cruza fin de semana y feriado: se omiten y no se registran
    ✓ ok
• Turnantes: FL, ADM y CT nunca consumen cupo; reemplazo obligatorio (sobre turnos cargados, SPEC-v2 §4.3)
    ✓ ok
• Rechazo y anulación liberan cupo; rechazada → autorizada re-verifica cupo
    ✓ ok
• Anulación: otra persona no puede; pasado el plazo falla; en plazo funciona; coordinación siempre
    ✓ ok
• Solicitudes superpuestas de la misma persona se rechazan
    ✓ ok
• Validaciones: fecha pasada, persona obligatoria (ya no hay «fuera de nómina») y código correlativo
    ✓ ok
• CSV de nómina: upsert por RUT y reporte de errores
    ✓ ok

── 02_concurrencia
• Concurrencia: dos procesos piden el último cupo → solo uno sin sobre cupo
    ✓ ok

── 03_seguridad
• Bug N1: coordinación nunca queda abierta sin clave, aunque config.php traiga coord_sin_clave
    ✓ ok
• Bug N2: la simulación no expone nombre, equipo ni calidad jurídica de nadie
    ✓ ok

── 04_migraciones
• Migraciones: base nueva queda en la última versión y repetir no cambia nada
    ✓ ok
• Migraciones: una base antigua (sin versión) se migra sin perder datos y con respaldo previo
    ✓ ok
• Migraciones: si una falla, se revierte y la versión no avanza
    ✓ ok
• Migraciones: la app migra sola al abrir una base antigua (sin SSH en DreamHost)
    ✓ ok

── 06_nomina
• Equipos: se siembran los 19 (incluye Oncología infantil y Neurología infantil)
    ✓ ok
• Migración 2: funcionarios tiene teléfono y rol (por defecto «funcionario»)
    ✓ ok
• Teléfono: se normaliza a +56… y se rechaza lo que no es un teléfono
    ✓ ok
• CSV de nómina: acepta la columna teléfono (opcional) y la guarda
    ✓ ok
• Edición de nómina: guarda el teléfono; el rol no se cambia desde ahí
    ✓ ok
• Privacidad: la búsqueda de nómina y el calendario nunca devuelven teléfono, RUT ni calidad
    ✓ ok
• Bug (encontrado en la etapa 1): los mensajes de error del CSV y de feriados muestran el dato que falló
    ✓ ok

── 07_cuentas
• Código de activación: 8 caracteres sin ambiguos, se guarda solo su hash y vence en 7 días
    ✓ ok
• Activar: con RUT + código válido se crea el PIN y queda iniciada la sesión; el código no se puede reusar
    ✓ ok
• Activar: código vencido o de otra persona no sirve
    ✓ ok
• PIN: 6 dígitos y no trivial (repetidos, secuencias, parte del RUT)
    ✓ ok
• Ingresar: RUT o PIN incorrectos dan el mismo mensaje; persona desactivada no entra
    ✓ ok
• Bloqueo por cuenta: 5 errores bloquean 15 min, luego 1 h, luego hasta que coordinación desbloquee; no por IP
    ✓ ok
• Sesión personal: dura 180 días desde el último uso y se renueva con el uso
    ✓ ok
• Sesión en computador compartido: vence a los 30 minutos sin uso
    ✓ ok
• Sesiones: se listan, se cierran una a una o todas; un código nuevo invalida el PIN y cierra las sesiones
    ✓ ok
• Cambiar PIN: exige el PIN actual; el nuevo debe ser válido
    ✓ ok
• Auditoría: activaciones, ingresos, fallos, bloqueos y códigos quedan registrados
    ✓ ok
• Herramienta de consola: crea el primer coordinador con su código de activación
    ✓ ok

── 08_acceso_http
• HTTP: sin sesión, todas las rutas protegidas responden 401 y no entregan datos
    ✓ ok
• HTTP: activar deja la sesión iniciada; la cookie y el CSRF funcionan; salir la cierra
    ✓ ok
• HTTP: ingresar con RUT + PIN; computador compartido; listar y cerrar sesiones; cambiar PIN
    ✓ ok
• HTTP: un funcionario no accede a coordinación (403); coordinación sí
    ✓ ok
• HTTP: coordinación genera códigos, desbloquea, cierra sesiones y asigna rol (sin quedar sin coordinadores)
    ✓ ok
• HTTP: límite de intentos por IP alto (solo contra ataques masivos)
    ✓ ok

── 09_instalador
• Instalador: crea la base, los equipos y la primera coordinadora con su código; luego se bloquea
    ✓ ok

── 10_solicitudes_sesion
• Solicitudes: quedan a nombre de quien inició sesión, aunque el cuerpo diga otra persona
    ✓ ok
• Mis solicitudes: cada persona ve solo las suyas (también buscando por código)
    ✓ ok
• Anular: solo la dueña (sin RUT) o coordinación; nadie anula la de otra persona
    ✓ ok
• Coordinación: registra «en nombre de» y resuelve firmando con su cuenta (sin «Resuelto por» a mano)
    ✓ ok
• Límite de envíos por cuenta, no por IP
    ✓ ok
• Ya no existen la solicitud «fuera de nómina» ni la vinculación
    ✓ ok
• Bug N2 por HTTP: simular con el id de otra persona calcula para quien tiene la sesión y no revela a la otra
    ✓ ok

── 11_tipos
• Tipos precargados: FL, ADM, CT y LIC con las reglas de SPEC-v2 §3.5
    ✓ ok
• Migración 4: reconstruir solicitudes y días conserva todo (ids, datos, días) y admite tipos nuevos
    ✓ ok
• Tipo nuevo creado por coordinación: se comporta según su configuración
    ✓ ok
• Tipos: sigla única e inmutable; los precargados solo cambian nombre, etiqueta y activo; nada se borra
    ✓ ok
• Quién pide: el funcionario no puede pedir tipos que registra coordinación (licencia)
    ✓ ok
• Config y calendario informan los tipos activos y la etiqueta pública
    ✓ ok

── 12_cupo_bloques
• Bloques: con cupo 2, el tercer medio día de la MAÑANA queda sin cupo (antes cabían 4)
    ✓ ok
• Bloques: un día completo ocupa mañana y tarde
    ✓ ok
• Bloques: FL ≥ 5 hábiles de titular no ocupa ningún bloque; FL de honorarios ocupa ambos
    ✓ ok
• Migración 5: los días ya guardados pasan a bloques según su fracción
    ✓ ok
• Concurrencia por bloque: cuatro procesos piden la última mañana → solo uno la obtiene
    ✓ ok
• Calendario y bandeja informan la ocupación por bloque
    ✓ ok

── 13_saldos
• Asignación anual FL 15 y ADM 6: automática y sin duplicarse
    ✓ ok
• Bug B7 (app anterior): un FL de lunes a domingo descuenta 5 días hábiles, no 7
    ✓ ok
• ADM medio día descuenta 0,5; las pendientes reservan saldo
    ✓ ok
• Bloqueo: si no alcanza el saldo, no se puede enviar; la simulación lo avisa
    ✓ ok
• Bug B5 (app anterior): autorizar → rechazar → autorizar no descuenta dos veces; anular devuelve
    ✓ ok
• Revertir a autorizada vuelve a verificar el saldo
    ✓ ok
• Ajustes: suman o restan con motivo obligatorio (p. ej. +5 FL por antigüedad)
    ✓ ok
• FL que cruza de año: cada año descuenta sus propios días hábiles; sin arrastre
    ✓ ok
• Turnantes: FL descuenta los días hábiles del rango; ADM media jornada 0,5 (SPEC-v2 §12.1 y §12.2)
    ✓ ok
• Tipos sin saldo (CT, LIC, tipos nuevos sin saldo) no descuentan nada
    ✓ ok
• Concurrencia de saldo: dos procesos usan el último día de ADM a la vez → solo uno lo obtiene
    ✓ ok
• HTTP: «Mi saldo» para cada persona; coordinación ve y ajusta saldos
    ✓ ok

── 14_licencias
• Licencia de un diurno: nace autorizada, firmada por coordinación, sin cupo ni saldo
    ✓ ok
• Licencia de un turnante: rango de fechas sin turnos ni reemplazo
    ✓ ok
• Coordinación puede registrar licencias con fechas pasadas; un funcionario no puede pedir fechas pasadas
    ✓ ok
• Licencia: coordinación puede anularla; si choca con otra solicitud lo dice con su código
    ✓ ok

── 15_resolucion
• Bug B4 (app anterior): si no se guarda, la respuesta es un error y no queda nada a medias
    ✓ ok
• No existe «volver a pendiente» ni borrar solicitudes: solo autorizar, rechazar, revertir y anular
    ✓ ok
• HTTP: revertir a autorizada sin saldo falla con un mensaje claro
    ✓ ok

── 16_turnos
• Turnos: se crean por unidad, fecha y tipo; no se duplican; el titular es turnante
    ✓ ok
• Carga en lote con patrón (largo, noche, libre, libre): repetible sin duplicar
    ✓ ok
• Turnos: listar por mes y unidad; editar; eliminar un turno sin solicitudes
    ✓ ok
• Mis turnos: cada turnante ve los suyos desde hoy
    ✓ ok

── 17_turnantes
• ADM de turnante: sobre un turno propio, con reemplazo elegido de la nómina
    ✓ ok
• Se exige turno cargado: sin turno, con turno ajeno o sin reemplazo, se rechaza
    ✓ ok
• FL de turnante: rango; se cubren todos sus turnos del rango; saldo = días hábiles del rango
    ✓ ok
• Cambio de turno: cede un turno propio a un compañero; la devolución es un turno del compañero
    ✓ ok
• Superposición: el mismo turno no puede estar en dos solicitudes activas
    ✓ ok

── 18_aplicar_turnos
• Autorizar una ausencia: el turno queda cubierto por el reemplazo, como extra por defecto (§12.4)
    ✓ ok
• Bug B6 (app anterior): si algo falla al autorizar, no queda nada a medias
    ✓ ok
• Bug B15 (app anterior): anular o rechazar restaura solo los turnos de ESA solicitud
    ✓ ok
• Cambio de turno autorizado: intercambia titulares (con y sin devolución); anular los restaura
    ✓ ok
• Licencia de un turnante: sus turnos del rango quedan «sin cubrir»; coordinación asigna reemplazo; anular restaura
    ✓ ok

── 19_horas_extra
• Horas por turno: cada fila de la tabla de SPEC-v2 §4.7
    ✓ ok
• Horas por turno: corrección manual repartida con las mismas reglas
    ✓ ok
• Resumen del mes: cuenta los turnos que se cubren como extra y los cargados como extra
    ✓ ok
• HTTP: coordinación ve el resumen y exporta CSV; un funcionario no
    ✓ ok

── 20_coord_vistas
• Hoy: ausentes de hoy, turnos sin cubrir y pendientes que vencen pronto o ya vencieron
    ✓ ok
• Registro de cambios: muestra quién hizo cada acción, con filtro de texto
    ✓ ok

── 21_carga_inicial
• Carga inicial --dry-run: informa lo que haría y no escribe nada
    ✓ ok
• Carga inicial: crea nómina y saldos; repetirla no duplica ni cambia nada; nunca borra
    ✓ ok
• Carga inicial --codigos: archivo local fuera de la app; repetir solo da códigos a quien no se ha activado
    ✓ ok

TODO OK (464 verificaciones)
```
