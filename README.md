# Panel de Ausentismos · Kinesiología HPM

App web interna para pedir días (FL, ADM, cambios de turno y los tipos que cree coordinación), ver el calendario de cupos por bloque mañana/tarde, llevar los saldos FL/ADM, el calendario de turnos de los turnantes y las horas extra. Cada persona entra con su RUT y un PIN de 6 dígitos; coordinación revisa, autoriza y configura con su propia cuenta.

PHP 8.1+ (sin Composer), SQLite, HTML/JS sin paso de compilación, diseño basado en Vital. Corre en un hosting compartido de DreamHost. Las reglas están en [SPEC-v2.md](SPEC-v2.md); el plan de trabajo, en [docs/plan.md](docs/plan.md).

## Estructura

```
public/                 → carpeta WEB (lo único accesible por internet)
  index.html            app de cada persona (celular primero)
  coordinacion/         vista de coordinación
  assets/               app.css, common.js, app.js, coord.js y vital/ (tokens, componentes y fuente Figtree)
  api.php               API (api.php?r=ruta)
  app-dir.php           dice dónde está la carpeta privada
  install.php           instalador de la primera vez (se bloquea solo; bórralo al terminar)
  .htaccess             HTTPS, bloqueos y cabeceras de seguridad
lib/                    reglas, base de datos, migraciones, cuentas      → carpeta PRIVADA
config.example.php      copiar como config.php                            → carpeta PRIVADA
herramientas/           consola: carga-inicial.php, codigo-acceso.php      (no se publican)
tests/                  pruebas: php tests/run.php                         (no se publican)
.github/workflows/      deploy.yml: publicación a DreamHost, se lanza a mano
```

## Desarrollo local

```bash
cp config.example.php config.php     # db_path a una ruta local, forzar_https => false
php public/install.php               # crea la base y la primera cuenta de coordinación (muestra su código)
php seed-demo.php                    # opcional: 70 personas ficticias con solicitudes y turnos
php -S localhost:8080 -t public      # http://localhost:8080 y /coordinacion/
php tests/run.php                    # todas las pruebas (reglas, concurrencia, login, HTTP, carga inicial)
```

Nunca uses datos reales en desarrollo: la nómina real (`nomina-hpm.csv`) está fuera del repositorio (`.gitignore`).

## Publicar en DreamHost

### Carpetas en el servidor

| Carpeta | Contenido | ¿Visible por web? |
|---|---|---|
| `/home/USUARIO/ausentismos-app/` | `lib/` y `config.php` | No |
| `/home/USUARIO/ausentismos-data/` | la base `.sqlite`, sus respaldos y `install.lock` | No |
| `/home/USUARIO/dominio.cl/ausentismos/` | el **contenido** de `public/` | Sí |

`config.php` se crea **una sola vez** a mano en el servidor (copia de `config.example.php`), con `db_path => '/home/USUARIO/ausentismos-data/ausentismos.sqlite'`. Ninguna publicación lo toca.

### Primera vez

1. Crea las tres carpetas y `config.php` (arriba). Activa HTTPS (Let's Encrypt) para el dominio en el panel de DreamHost.
2. Sube los archivos, de una de estas dos formas:
   - **Con GitHub Actions** (recomendado, ver abajo): lánzalo con «incluir_instalador» marcado.
   - **A mano por SFTP:** `lib/` a la carpeta privada; el contenido de `public/` a la carpeta web; y en la copia del servidor de `app-dir.php` escribe `$APP_DIR_PRIVADA = '/home/USUARIO/ausentismos-app';`.
3. Abre `https://dominio.cl/ausentismos/install.php`, crea la primera cuenta de coordinación y anota su código. **Borra `install.php`** del servidor (aunque quede, se niega a correr otra vez).
4. Entra a la app, activa tu cuenta con el código y crea tu PIN.
5. Carga la nómina: en **Coordinación → Nómina → Importar CSV** (columnas `nombre,rut,jornada,equipo,calidad[,telefono]`). Repetir la importación no duplica ni borra a nadie. Los saldos FL 15 y ADM 6 del año se asignan solos.
   - Alternativa por SSH, con informe previo: sube `herramientas/` y el CSV a la carpeta privada y corre `php herramientas/carga-inicial.php --csv=nomina.csv --dry-run`; si se ve bien, sin `--dry-run`. Con `--codigos --salida=/home/USUARIO/` deja un archivo con los códigos de activación de todos (fuera de la web). Luego borra del servidor el CSV, el archivo de códigos y `herramientas/`.
6. Entrega los códigos de activación uno a uno (Nómina → «Código de acceso», o el archivo del paso anterior). Carga feriados y revisa cupos.

### Publicar una actualización sin perder datos

Los datos viven solo en el `.sqlite` de `ausentismos-data/` y en `config.php`; una publicación solo reemplaza código (`lib/` y `public/`), **nunca** esos dos.

1. Corre `php tests/run.php` en tu equipo (el workflow lo vuelve a correr).
2. Descarga un respaldo (Coordinación → Exportar y respaldo). El workflow además hace uno en el servidor antes de subir (`<base>.antes-publicar-<fecha>.sqlite`).
3. Publica (workflow o SFTP). No uses opciones de «borrar en destino».
4. Si la versión nueva trae cambios de base, se aplican solos en la primera visita y, antes, se deja otro respaldo (`<base>.antes-v<N>-<fecha>.sqlite`).
5. Revisa que entras, que el calendario carga y que `config.php`, `lib/` y `*.sqlite` siguen dando 403/404.

**Volver atrás:** vuelve a publicar la versión anterior del código y, si hiciera falta, reemplaza la base por el respaldo `antes-…` (con la app detenida o en un momento sin uso).

### GitHub Actions (`.github/workflows/deploy.yml`)

No corre solo: se lanza en GitHub → Actions → «Publicar en DreamHost» → Run workflow. Corre las pruebas; si pasan, respalda la base en el servidor y sube por SSH/rsync `lib/` y `public/` (sin borrar nada en el servidor y sin `install.php`, salvo que marques «incluir_instalador»). Ajusta `app-dir.php` con la ruta privada.

Necesita estos secretos del repositorio: `DH_HOST`, `DH_USER`, `DH_SSH_KEY` (llave privada; la pública va en `~/.ssh/authorized_keys` del usuario en DreamHost), `DH_WEB_DIR`, `DH_APP_DIR`, `DH_DB_PATH`. Opcional: en Settings → Environments, crea `produccion` y exige aprobación para que nadie publique sin que lo apruebes.

## Respaldo

- **Manual:** Coordinación → Exportar y respaldo → «Descargar respaldo (.sqlite)». Es una copia consistente aunque la app esté en uso.
- **Programado (cron de DreamHost):** en el panel → Advanced → Cron Jobs, por ejemplo cada noche a las 3:00:
  ```
  sqlite3 /home/USUARIO/ausentismos-data/ausentismos.sqlite ".backup '/home/USUARIO/ausentismos-respaldos/aus-$(date +\%F).sqlite'"
  ```
  (Crea antes `ausentismos-respaldos/`, fuera de la web. Si quieres limitar el espacio, puedes agregar una segunda tarea que borre respaldos de más de 60 días; eso es decisión tuya.)
- No copies el `.sqlite` suelto mientras está en uso: con WAL hay también archivos `-wal` y `-shm`.
- Los respaldos contienen RUT y teléfonos: guárdalos con cuidado y no los compartas.

## Acceso y cuentas

- Cada persona entra con **su RUT y un PIN de 6 dígitos**. La primera vez usa un **código de activación** (8 caracteres, vence en 7 días) que le entrega coordinación.
- En su celular la sesión dura 180 días; si marca «computador compartido», 30 minutos sin uso.
- Olvidó el PIN: coordinación genera un código nuevo (invalida el PIN anterior y cierra sus sesiones).
- 5 PIN errados bloquean la cuenta 15 minutos; luego 1 hora; luego hasta que coordinación la desbloquee.
- **Emergencia** (nadie de coordinación puede entrar): sube `herramientas/codigo-acceso.php` a la carpeta privada y por SSH corre `php herramientas/codigo-acceso.php <RUT> --coordinacion`. Bórralo después.

## Comprobaciones al publicar (hacer juntos en DreamHost)

En local no se pueden probar las reglas de `.htaccess` de Apache. Tras la primera publicación:

- [ ] `https://dominio.cl/ausentismos/` redirige de http a https.
- [ ] Dan 403 o 404: `/ausentismos/config.php`, `/ausentismos/app-dir.php`, `/ausentismos/install.php` (tras borrarlo), `/ausentismos/lib/`, cualquier `*.sqlite`, `*.csv` y `*.md`.
- [ ] La cabecera `Content-Security-Policy` llega y la app se ve con su fuente y estilos (sin errores en la consola).
- [ ] `ausentismos-data/` no es accesible por web.
- [ ] Entrar, pedir un día, autorizarlo desde coordinación y verlo en el calendario.
- [ ] Decidir en qué carpeta va la app nueva: la app anterior (React/Firebase) sigue publicada aparte y esta publicación no la toca.

## Preparado para después

- `lib/notificar.php`: `notificar()` vacía, llamada al crear y al resolver (correo o WhatsApp más adelante).
- `config.php`: `anticipacion_min_dias` y `horizonte_max_dias` (desactivados) y `fl_largo_dias_habiles`.
