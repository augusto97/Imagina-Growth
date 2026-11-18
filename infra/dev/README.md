# Desarrollo local sin Docker

El hub corre directo en tu máquina, con MariaDB 11.4, ClickHouse 25.8 y Redis 7 nativos (las mismas versiones que
producción, `docs/despliegue.md`).

## 1. Bases de datos

```bash
sudo infra/dev/setup-local.sh      # Ubuntu o Debian (repositorios oficiales)
infra/dev/setup-local.sh           # macOS (Homebrew; ClickHouse con su binario oficial)
```

El script instala y arranca las tres, y crea:
- **MariaDB:** el usuario `igr` con contraseña `igr` y las bases `igr`, `igr_test`, `wp_igr` y `wp_igr_tests`. También le da permiso sobre `igr*` y `wp_igr*` para las bases que crean las pruebas.
- **ClickHouse:** el usuario `igr` con contraseña `igr` y la base `igr`.
- **Redis:** una sola instancia en el 6379, sin contraseña. En desarrollo, la caché y las colas comparten esa instancia; en producción van separadas (6379 y 6380).

Al final imprime los valores para `hub/.env`. Correrlo otra vez no cambia nada.

## 2. El hub

Requisitos: PHP 8.4 con las extensiones `pdo_mysql`, `redis`, `intl`, `zip`, `pcntl`, `sockets`, `gd`, `exif`, `gmp`, `bcmath` y `sodium`; Composer; Node 22; y [uv](https://docs.astral.sh/uv/) para el servicio de predicciones.

```bash
cd hub
cp .env.example .env               # completa APP_KEY e IGR_BLIND_INDEX_KEY (ver el comentario del archivo)
composer install
npm ci
php artisan key:generate
php artisan migrate
php artisan igr:clickhouse:migrate
```

Procesos, cada uno en su terminal (en producción son servicios de systemd, `docs/despliegue.md` §1):

| Proceso | Comando |
|---|---|
| Panel y API | `php artisan serve` (http://localhost:8000) |
| SPA con recarga en caliente | `npm run dev` |
| Worker de ingesta | `php artisan igr:ingest:work` |
| Colas | `php artisan horizon` |
| Tareas programadas | `php artisan schedule:work` |
| Tiempo real (opcional) | `php artisan reverb:start --port=8080`, con `BROADCAST_CONNECTION=reverb` |
| Predicciones (opcional) | `cd ml && uv sync && IGR_ML_TOKEN=dev-ml-token uv run uvicorn app.main:app --port 8095` |

- **Correo:** con `MAIL_MAILER=log`, los correos quedan en `storage/logs`. Si prefieres verlos en un buzón, levanta [Mailpit](https://mailpit.axllent.org/) (binario o `brew install mailpit`) y deja `MAIL_HOST=127.0.0.1` y `MAIL_PORT=1025`.
- **Datos de prueba sin WordPress:** `php artisan igr:seed-demo --process` crea «Pinturas Demo» (usuario `demo@growth.test`, contraseña `demo-growth-2026`), con 2.000 clientes y 10.000 pedidos que pasan por la ingesta.

## 3. Tienda WooCommerce de prueba

Con PHP 8.3 o superior, [WP-CLI](https://wp-cli.org/) y la base `wp_igr` del paso 1:

```bash
mkdir -p ~/sitios/tienda && cd ~/sitios/tienda
wp core download --locale=es_CO
wp config create --dbname=wp_igr --dbuser=igr --dbpass=igr --dbhost=127.0.0.1 \
  --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'IGR_HUB_URL', 'http://127.0.0.1:8000' );
PHP
ln -s /ruta/a/Imagina-Growth/connector wp-content/plugins/imagina-growth-connector
sh /ruta/a/Imagina-Growth/infra/wordpress/igr-setup.sh 10000 2000   # WooCommerce, el plugin y la tienda demo
php -S 127.0.0.1:8080
```

Luego entra a `http://127.0.0.1:8080/wp-admin` (usuario `admin`, contraseña `admin`) y ve a **Imagina Growth → Conectar**.

## 4. Pruebas

- **Hub:** `vendor/bin/pest`. Usa la base `igr_test`; en paralelo, cada proceso tiene la suya.
- **Instalador de producción:** `infra/tests/install/run.sh` lo prueba en un Ubuntu 24.04 con systemd, dentro de un contenedor desechable que sirve solo para verificar (ver la cabecera del script).
