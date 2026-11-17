# Panel de Ausentismos · Kinesiología HPM

Aplicación web para que los kinesiólogos pidan días de ausentismo, vean el calendario con cupos y consulten si su solicitud fue autorizada. Coordinación revisa y resuelve con una clave compartida. PHP 8.1+ sin Composer, SQLite, HTML/JS sin build.

No reemplaza RR.HH.: no lleva saldos de feriado ni administrativos, ni licencias médicas.

## Estructura

```
public/                 → se sube a la carpeta web (p. ej. /home/USUARIO/dominio.cl/ausentismos/)
  index.html            app pública
  coordinacion/         vista de coordinación (clave compartida)
  assets/               app.css, common.js, app.js, coord.js
  api.php               enrutador de la API (?r=ruta)
  app-dir.php           ruta a la carpeta privada (lib + config)
  install.php           instalador (se auto-bloquea; bórralo al terminar)
  .htaccess             HTTPS, bloqueos y cabeceras de seguridad
lib/                    reglas de negocio, base de datos, auth, handlers  → PRIVADO
config.example.php      copiar como config.php                            → PRIVADO
seed-demo.php           datos de demostración (solo por línea de comandos)
tests/run.php           pruebas de las reglas (sin dependencias)
nomina-ejemplo.csv      formato de la nómina
```

## Despliegue en DreamHost

1. **Crea las carpetas.** Por SFTP/SSH:
   - `/home/USUARIO/ausentismos-app/` → privada. Sube aquí `lib/` y `config.php`.
   - `/home/USUARIO/ausentismos-data/` → privada. Aquí vivirá el `.sqlite` (nunca dentro de la carpeta web).
   - `/home/USUARIO/dominio.cl/ausentismos/` → web. Sube aquí el **contenido** de `public/` (incluido `.htaccess`).
2. **Configura.** Copia `config.example.php` como `config.php` y ajusta:
   - `db_path` → `/home/USUARIO/ausentismos-data/ausentismos.sqlite`
   - `coord_sin_clave` → `true` (coordinación sin clave) o `false` + `clave_hash` (ver «Acceso a coordinación»)
   - `titulo`, `logo` (opcional), límites.
3. **Apunta la web a la carpeta privada.** Edita `app-dir.php` (en la carpeta web) y escribe `$APP_DIR_PRIVADA = '/home/USUARIO/ausentismos-app';`.
4. **HTTPS.** En el panel de DreamHost activa Let's Encrypt para el dominio. El `.htaccess` redirige a HTTPS.
5. **Instala.** Abre `https://dominio.cl/ausentismos/install.php` y pulsa «Instalar ahora» (crea el esquema y siembra los 19 equipos). También funciona por SSH: `php install.php`.
6. **Borra `install.php`** del servidor. (Aunque lo dejes, se niega a correr de nuevo porque existe `ausentismos-data/install.lock`.)
7. Entra a `https://dominio.cl/ausentismos/coordinacion/`, carga la nómina (CSV), los feriados y ajusta los cupos.

### Acceso a coordinación

Por defecto (`coord_sin_clave => true`) la vista `/coordinacion/` **no pide clave**: quien conozca el enlace puede autorizar y modificar todo. Sugerencias: no compartas ese enlace y, si quieres, renombra la carpeta `coordinacion` por algo difícil de adivinar (ej. `coord-k7x2m`); funciona con cualquier nombre. Si algún día quieres clave: pon `coord_sin_clave => false` y un `clave_hash` generado con:

```bash
php -r "echo password_hash('NUEVA-CLAVE-LARGA', PASSWORD_DEFAULT), PHP_EOL;"
```

### Respaldo

- Desde `/coordinacion` → «Exportar y respaldo» → «Descargar respaldo (.sqlite)» (usa `VACUUM INTO`, copia consistente aunque haya uso).
- O por SSH: `sqlite3 ausentismos.sqlite ".backup respaldo.sqlite"`. No copies el archivo suelto mientras está en uso: con WAL hay también `-wal` y `-shm`.
- Contiene RUT de funcionarios: guárdalo con cuidado.

### Verificar que no se filtra nada

Estas URL deben dar 403/404 (revísalas tras subir): `/ausentismos/config.php`, `/ausentismos/lib/`, `/ausentismos/install.php` (tras borrarlo), cualquier `*.sqlite`.

## Reglas implementadas (resumen)

- **Cupo solo para diurnos.** Por equipo y día (inicial 2, configurable, admite 0,5). Turnantes nunca consumen ni bloquean.
- Consumo: ADM completo 1; ADM ½ día 0,5; FL < 5 días hábiles 1 por día; FL ≥ 5 días hábiles de titular/contrata 0 (aparece igual en el calendario); FL de honorarios siempre 1.
- Días hábiles = lunes a viernes no feriados. Los demás se omiten automáticamente.
- Ocupan cupo `pendiente` y `autorizada`; liberan `rechazada` y `anulada`.
- **Sobre cupo:** el servidor avisa los días llenos; el funcionario puede enviarla marcada «sobre cupo» con justificación obligatoria.
- **Sin condición de carrera:** validación de cupo + inserción en una transacción `BEGIN IMMEDIATE`.
- Resolución completa (no por días). Coordinación puede revertir; al pasar a autorizada se re-verifica el cupo.
- Anulación del funcionario: exige su RUT y es posible hasta las 23:59 del día anterior a la primera fecha.
- No se permiten solicitudes activas superpuestas de la misma persona.

## Cambios respecto al borrador de la API

Se usa siempre `api.php?r=ruta` (no depende de reescritura de URL). Diferencias menores:

- `GET api.php?r=calendario&mes=2026-12` devuelve **todas** las ausencias del mes (sin RUT); el cliente calcula la vista (equipo / todos / turnantes). Coordinación usa `coord/calendario` (con datos completos).
- Se agregó `POST solicitudes/simular`: calcula días hábiles, consumo y días llenos con las mismas reglas del servidor, para el resumen en vivo del formulario (no escribe nada).
- Se agregó `coord/solicitudes/{id}/anular` y `/vincular`, y `coord/sesion`.
- La búsqueda pública de nómina no devuelve RUT ni calidad. La calidad se muestra en el formulario solo después de simular, para la persona elegida.
- ADM es de un solo día (completo o medio día), como en el borrador; FL admite rangos.

## Datos de demostración y pruebas (desarrollo)

```bash
php tests/run.php         # reglas de negocio, incluye la prueba de concurrencia
php seed-demo.php         # 70 funcionarios ficticios y solicitudes (usa tu config.php)
```

Para correr en local: copia `config.example.php` a `config.php`, pon `db_path` a una ruta local, `forzar_https => false`, y:

```bash
php install.php
php -S localhost:8080 -t public
```

## Preparado para después

- `lib/notificar.php`: función `notificar()` vacía, llamada al crear y al resolver (correo en fase 2).
- `config.php`: `anticipacion_min_dias` y `horizonte_max_dias` (desactivados), `fl_largo_dias_habiles`.
- Cupos por fecha / bloqueos: la ocupación se calcula desde `solicitud_dias`, así que se puede agregar una tabla de excepciones sin tocar el resto.

## Riesgo aceptado (piloto)

Sin cuentas, cualquiera podría crear una solicitud a nombre de otra persona. Mitigación: cada solicitud guarda IP, user agent y hora (visibles en coordinación), la anulación exige el RUT, hay límite de envíos por IP y un campo trampa (honeypot).
