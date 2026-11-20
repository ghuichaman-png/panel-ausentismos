# Cómo subir la app a DreamHost y dejarla funcionando

Guía paso a paso para la **primera publicación**, usando solo el panel de DreamHost y un programa SFTP (FileZilla o WinSCP). No necesitas SSH. Al final está cómo **actualizar** sin perder datos y, opcional, cómo publicar con GitHub Actions.

En toda la guía reemplaza:

| Donde dice | Pon |
|---|---|
| `USUARIO` | tu usuario SFTP de DreamHost (p. ej. `kineshpm`) |
| `ausentismos.TU-DOMINIO.cl` | el subdominio que vas a usar |

> Los nombres de los menús del panel de DreamHost cambian de vez en cuando. Si no encuentras uno tal cual, busca el más parecido.

---

## 0. Antes de empezar

- [ ] Acceso al panel de DreamHost (panel.dreamhost.com).
- [ ] FileZilla o WinSCP instalado en tu computador.
- [ ] Esta carpeta del proyecto (`panel ausentismos`) con la versión que vas a publicar. Si vas a publicar lo que está en GitHub, primero fusiona el PR pendiente y actualiza tu copia.
- [ ] Opcional: correr las pruebas en tu equipo. Deben terminar en «TODO OK».
  ```powershell
  php tests/run.php
  ```
- [ ] El archivo de la nómina real (`nomina-hpm.csv`). **No se sube al servidor**: se importa desde la app.

---

## 1. Crear el subdominio en DreamHost

1. Panel → **Websites** → **Add Website** (o «Manage Websites» → «Add»).
2. Escribe `ausentismos.TU-DOMINIO.cl` y elige alojarlo como sitio web normal («Fully hosted»).
3. Usuario: elige tu usuario `USUARIO`.
4. Carpeta web: deja la que propone, normalmente `/home/USUARIO/ausentismos.TU-DOMINIO.cl`. **Anótala**: es la «carpeta web».
5. Versión de PHP: elige **8.3** (sirve 8.1 o más nueva).
6. Guarda. Si el dominio **no** está administrado por DreamHost, en el proveedor de tu dominio crea un registro DNS tipo **A** apuntando a la IP que te muestre DreamHost para ese sitio. Puede tardar unas horas en propagarse.

### Activar HTTPS

7. Panel → **Websites** → el subdominio → **SSL/TLS** (o «Secure Certificates») → agrega el certificado gratuito **Let's Encrypt**.
8. Espera a que quede activo (suele tardar minutos, a veces hasta una hora). La app obliga a usar https, así que sin certificado no se podrá usar.

---

## 2. Conectarte por SFTP

En FileZilla o WinSCP:

- Protocolo: **SFTP**
- Servidor: el que aparece en el panel junto a tu usuario (p. ej. `iad1-shared-b7-01.dreamhost.com`), o directamente `ausentismos.TU-DOMINIO.cl`
- Usuario y contraseña: los de `USUARIO`

Al entrar quedas en `/home/USUARIO/`. Debes ver la carpeta web `ausentismos.TU-DOMINIO.cl`.

**Muestra los archivos ocultos**, porque hay que subir `.htaccess`:
- FileZilla: menú Servidor → «Forzar mostrar archivos ocultos».
- WinSCP: Opciones → Preferencias → Paneles → «Mostrar archivos ocultos».

---

## 3. Crear las carpetas en el servidor

Dentro de `/home/USUARIO/` crea **dos carpetas nuevas**, al mismo nivel que la carpeta web (no dentro de ella):

```
/home/USUARIO/
├── ausentismos.TU-DOMINIO.cl/   ← carpeta WEB (ya existe): lo único visible por internet
├── ausentismos-app/             ← NUEVA: código privado (lib/) y config.php
└── ausentismos-data/            ← NUEVA: la base de datos y sus respaldos
```

Así ni el código interno ni la base quedan alcanzables desde internet.

---

## 4. Preparar dos archivos en tu computador

Trabaja sobre **copias**, en una carpeta aparte (por ejemplo `Escritorio\subir-ausentismos`), para no modificar el proyecto.

### 4.1 `config.php`

1. Copia `config.example.php` y renombra la copia a `config.php`.
2. Ábrela con el Bloc de notas y cambia solo esta línea:
   ```php
   'db_path'  => '/home/USUARIO/ausentismos-data/ausentismos.sqlite',
   ```
   (con tu usuario real en lugar de `USUARIO`).
3. Deja `'forzar_https' => true,`.
4. Si quieres, cambia `'titulo'`. Lo demás déjalo igual.

### 4.2 `app-dir.php`

1. Copia `public/app-dir.php`.
2. Cambia la línea:
   ```php
   $APP_DIR_PRIVADA = '';
   ```
   por:
   ```php
   $APP_DIR_PRIVADA = '/home/USUARIO/ausentismos-app';
   ```

> **Importante:** esta copia ajustada de `app-dir.php` es la que vive en el servidor. En cada actualización futura **no la reemplaces** por la del proyecto, que viene vacía. Si la reemplazas, la app mostrará «No se encontró la carpeta lib/».

---

## 5. Subir los archivos

| Del computador | Al servidor |
|---|---|
| carpeta `lib/` completa | `/home/USUARIO/ausentismos-app/lib/` |
| tu `config.php` (paso 4.1) | `/home/USUARIO/ausentismos-app/config.php` |
| **el contenido** de `public/` (no la carpeta en sí): `index.html`, `api.php`, `install.php`, `app-dir.php`, `.htaccess`, `assets/`, `coordinacion/` | `/home/USUARIO/ausentismos.TU-DOMINIO.cl/` |
| tu `app-dir.php` ajustado (paso 4.2) | encima del anterior, en `/home/USUARIO/ausentismos.TU-DOMINIO.cl/app-dir.php` |

**No subas:** `tests/`, `herramientas/`, `docs/`, `seed-demo.php`, `config.example.php`, ningún `.csv`, `.sqlite` ni `.zip`, ni la carpeta `.git`.

Al terminar debería quedar así:

```
/home/USUARIO/ausentismos-app/
    config.php
    lib/   (bootstrap.php, db.php, handlers_coord.php, …)
/home/USUARIO/ausentismos-data/
    (vacía por ahora)
/home/USUARIO/ausentismos.TU-DOMINIO.cl/
    .htaccess
    api.php
    app-dir.php      ← el ajustado
    index.html
    install.php
    assets/
    coordinacion/
```

Si DreamHost dejó un archivo de bienvenida en la carpeta web (p. ej. `index.php` o `quickstart.html`), bórralo.

---

## 6. Instalar (crea la base y tu cuenta de coordinación)

1. Abre en el navegador: `https://ausentismos.TU-DOMINIO.cl/install.php`
2. Completa el formulario con **tus** datos (serás la primera persona de coordinación): nombre, RUT, jornada, equipo y calidad. Pulsa **«Instalar ahora»**.
3. Aparece un **código de activación** grande. **Anótalo**: se muestra una sola vez y vence en 7 días.
4. **Borra `install.php`** de la carpeta web por SFTP. Aunque lo dejaras, ya no volvería a funcionar, pero es mejor que no esté.

Si muestra un error, revisa la sección **Problemas frecuentes** al final.

---

## 7. Entrar por primera vez

1. Abre `https://ausentismos.TU-DOMINIO.cl/` (idealmente en tu celular).
2. Elige **«Activar con código»**, escribe tu RUT y el código del paso 6.
3. Crea tu **PIN de 6 dígitos**. No sirven PIN obvios como 123456 o 111111.
4. Entra a **Coordinación** (pestaña de abajo en el celular o botón arriba en el computador). Para trabajar cómodo, usa un computador: `https://ausentismos.TU-DOMINIO.cl/coordinacion/`

---

## 8. Dejarla lista para el equipo

En la vista de coordinación:

1. **Equipos**: revisa los 19 equipos y el **cupo diurno** de cada uno (cuántas personas pueden faltar el mismo día).
2. **Feriados**: pega los feriados del año, uno por línea, como `2027-01-01 Año Nuevo` o `01-01-2027 Año Nuevo`.
3. **Tipos**: revisa los tipos de ausentismo (FL, ADM, etc.) y cómo consumen cupo y saldo.
4. **Nómina → «Importar CSV»** → elige `nomina-hpm.csv` desde tu computador.
   - Columnas: `nombre,rut,jornada,equipo,calidad` y, opcional, `telefono` (separadas por coma o punto y coma).
   - El resultado dice cuántas personas se crearon, actualizaron y quedaron con error, indicando la línea. Corrige esas líneas en el CSV e impórtalo de nuevo: repetir no duplica ni borra a nadie.
   - Los saldos del año (**FL 15 y ADM 6**) se asignan solos.
5. **Saldos**: si alguien ya usó días este año, ajústale el saldo indicando el motivo.
6. **Turnos**: carga el calendario de turnos de los turnantes. Sin turno cargado, un turnante no puede pedir días.
7. **Códigos para cada persona**: en **Nómina**, botón **«Cuenta…»** de cada persona → **«Generar código de acceso»**. Entrégalo en persona o por WhatsApp. Cada persona hace lo mismo que tú en el paso 7.

---

## 9. Comprobar que todo quedó bien protegido

Abre cada dirección en el navegador:

| Dirección | Resultado esperado |
|---|---|
| `http://ausentismos.TU-DOMINIO.cl/` (con **http**) | te lleva solo a **https** |
| `https://ausentismos.TU-DOMINIO.cl/` | la app con sus colores y letra |
| `https://ausentismos.TU-DOMINIO.cl/app-dir.php` | **403** (prohibido) o 404 |
| `https://ausentismos.TU-DOMINIO.cl/install.php` | **404** (ya lo borraste) |
| `https://ausentismos.TU-DOMINIO.cl/lib/` | **404** |
| `https://ausentismos.TU-DOMINIO.cl/nomina-hpm.csv` | **403** o 404 |
| `https://ausentismos.TU-DOMINIO.cl/README.md` | **403** o 404 |

Y una prueba completa de uso:

- [ ] Con una cuenta de prueba o la tuya: pedir un día.
- [ ] Autorizarlo desde Coordinación → Pendientes.
- [ ] Verlo en el calendario.
- [ ] Anularlo y ver que el saldo vuelve.

Si la app se ve **sin estilos** o en blanco, abre la consola del navegador (F12) y mándame lo que diga.

---

## 10. Respaldos

- **Manual (hazlo al menos una vez por semana al principio):** Coordinación → **Respaldo** → «Descargar respaldo (.sqlite)». Es una copia completa y consistente, aunque la app esté en uso.
- **Automático, cada noche (opcional):** en el panel → **Advanced → Cron Jobs** → nueva tarea con tu usuario, todos los días a las 3:00. Primero crea la carpeta `/home/USUARIO/ausentismos-respaldos/` por SFTP. Comando:
  ```
  sqlite3 /home/USUARIO/ausentismos-data/ausentismos.sqlite ".backup '/home/USUARIO/ausentismos-respaldos/aus-$(date +\%F).sqlite'"
  ```
  Si DreamHost te pide un usuario con acceso «Shell» para los cron, cámbialo en el panel (ver sección 12).
- No copies el `.sqlite` suelto por SFTP mientras la app está en uso: puede salir incompleto. Usa el botón de respaldo.
- Los respaldos tienen RUT y teléfonos: guárdalos en un lugar seguro y no los compartas.

---

## 11. Publicar una actualización (sin perder datos)

Los datos viven **solo** en `ausentismos-data/` y en `config.php`. Una actualización reemplaza código, nunca esos dos.

1. Descarga un respaldo (Coordinación → Respaldo).
2. Por SFTP, sube y **sobrescribe**:
   - `lib/` → `/home/USUARIO/ausentismos-app/lib/`
   - el contenido de `public/` → carpeta web, **excepto**:
     - `app-dir.php`: no lo subas, deja el del servidor;
     - `install.php`: no lo subas.
3. No borres nada en el servidor ni uses la opción «sincronizar y borrar».
4. Abre la app. Si la versión trae cambios en la base, se aplican solos en la primera visita y antes deja un respaldo automático (`ausentismos.sqlite.antes-v…`).
5. Repite las comprobaciones de la sección 9.

**Volver atrás:** sube otra vez el código anterior. Si hiciera falta, reemplaza `ausentismos.sqlite` por el respaldo `antes-…`, en un momento sin uso.

---

## 12. Opcional: publicar con GitHub Actions

En lugar de usar SFTP, GitHub puede subir la app con un botón. Además corre las pruebas y respalda la base antes de cada publicación. Necesita SSH:

1. Panel → **Websites → SFTP Users & Domains** (o «Manage Users») → edita `USUARIO` → tipo de acceso **Shell (SSH)**. Guarda y espera unos minutos.
2. La llave para GitHub ya está creada en tu computador: `C:\Users\HPM\.ssh\panel-ausentismos-deploy` (privada) y `panel-ausentismos-deploy.pub` (pública).
3. En el servidor, crea `/home/USUARIO/.ssh/` si no existe. Dentro, crea o edita `authorized_keys` y pega en una línea nueva el contenido completo de `panel-ausentismos-deploy.pub`.
4. En GitHub → el repositorio → **Settings → Secrets and variables → Actions → New repository secret**, crea:

   | Secreto | Valor |
   |---|---|
   | `DH_HOST` | el servidor SSH (p. ej. `iad1-shared-b7-01.dreamhost.com`) |
   | `DH_USER` | `USUARIO` |
   | `DH_SSH_KEY` | el contenido completo de `panel-ausentismos-deploy` (la **privada**, incluidas las líneas BEGIN y END) |
   | `DH_WEB_DIR` | `/home/USUARIO/ausentismos.TU-DOMINIO.cl` |
   | `DH_APP_DIR` | `/home/USUARIO/ausentismos-app` |
   | `DH_DB_PATH` | `/home/USUARIO/ausentismos-data/ausentismos.sqlite` |

5. Recomendado: **Settings → Environments → produccion** → «Required reviewers» → tú. Así nada se publica sin que lo apruebes.
6. Para publicar: **Actions → «Publicar en DreamHost» → Run workflow** (rama `main`). Marca «incluir_instalador» **solo** si es la primera instalación.

`config.php` igual se crea a mano la primera vez (paso 4.1). El flujo nunca lo toca, ni toca la base.

---

## Problemas frecuentes

| Ves | Qué hacer |
|---|---|
| «No se encontró la carpeta lib/. Revisa app-dir.php.» | El `app-dir.php` del servidor no tiene la ruta correcta (paso 4.2) o `lib/` no quedó en `ausentismos-app/lib/`. |
| «Falta config.php…» | `config.php` no está en `/home/USUARIO/ausentismos-app/`. |
| «Ajusta db_path en config.php…» | Todavía dice `USUARIO` o está vacío: pon tu usuario real. |
| «Falta la extensión pdo_sqlite» | Cambia la versión de PHP del sitio a 8.3 en el panel. |
| «El sistema ya está instalado» | Ya se instaló. Borra `install.php` y entra a la app. |
| Error 500 o página en blanco | Revisa la versión de PHP (8.1 o más nueva). El registro de errores está en `/home/USUARIO/logs/ausentismos.TU-DOMINIO.cl/https/error.log`. |
| «Tu conexión no es privada» o no carga con https | El certificado Let's Encrypt aún no está activo: espera y vuelve a intentar. |
| La app sin estilos | Revisa que se subió la carpeta `assets/` completa (incluida `assets/vital/`) y el `.htaccess`. |
| Nadie de coordinación puede entrar | Necesitas SSH (sección 12, paso 1). Sube `herramientas/codigo-acceso.php` a `ausentismos-app/herramientas/` y corre `php ~/ausentismos-app/herramientas/codigo-acceso.php TU-RUT --coordinacion`. Luego borra la carpeta `herramientas/`. |

¿Algo no calza con lo que ves en el panel? Mándame una captura y lo ajustamos.
