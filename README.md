# Simple Voting System — Drupal 11 Environment

This repository is the foundation for the "Simple Voting System" technical test: a containerised
Drupal 11 development environment, a scripted and repeatable installation, and a custom theme
skeleton built on Bootstrap Barrio. **It does not yet contain the voting feature.** There is no
content type, no custom entity, no custom module and no application logic — only the platform those
things will be built on, plus the documentation explaining why it is put together the way it is.

One command brings up the whole stack and installs Drupal from scratch.

---

## Requirements

- Docker Engine with the Compose V2 plugin (`docker compose`, not `docker-compose`)
- GNU Make
- A POSIX shell (Linux or macOS; on Windows use WSL2)
- Network access to Docker Hub, `deb.debian.org` and `github.com` for the image build, and to
  `repo.packagist.org` and `packages.drupal.org` for the first `composer install`

Nothing else is needed on the host: PHP, Composer, Drush and Dart Sass are all provided by the
containers. Composer and Dart Sass are baked into the PHP image; Drush arrives in `vendor/` through
`composer install`.

---

## Quick start

```bash
git clone <repository-url> sistema-de-votacao
cd sistema-de-votacao
make install
```

`make install` chains four steps: it creates `.env` from `.env.example` if it is missing, builds the
images, starts the containers, and then runs `scripts/install.sh` inside the `php` container as
`www-data`. The script prints 13 progress steps, each prefixed with `==>`, and ends with a one-time
login link.

With `composer.lock` present and the images already built, a full `make install` — dependency
install and Drupal installation — completed in about 145 seconds on the reference machine. The first
run also has to build the images, which depends on your network.

When it finishes:

| What | Where |
| --- | --- |
| Site | <http://localhost:8081> |
| Admin login | `admin` / `admin` (from `.env`) |
| One-time login link | printed by the installer, or `make uli` |
| Database client (Adminer) | <http://localhost:8082> |
| Mail inbox (Mailpit) | <http://localhost:8025> |

`.env` does not exist yet after cloning — `make init` creates it, and `make install` runs `make init`
as its first step. If port 8081, 8082 or 8025 is already taken on your machine, run `make init`
first, adjust `HTTP_PORT`, `ADMINER_PORT` or `MAILPIT_PORT` in the generated `.env` (updating
`DRUPAL_BASE_URL` to match if you changed `HTTP_PORT`), then run `make install`.

Re-running the installer is safe. It detects an installed site, skips `site:install`, and its
`settings.php` edits are idempotent — they match on the literal line before appending, so nothing is
duplicated. To wipe the site and start over:

```bash
make up            # reinstall runs inside the php container, so the stack must be up
make reinstall     # drops the database and installs again (FORCE_INSTALL=1)
```

`make reinstall` uses `docker compose exec`, not `run`, so it fails with `service "php" is not
running` if the stack is down. Run `make up` first — also after any `.env` change, so the `php`
container is recreated with the new values.

---

## Services

Defined in `compose.yaml`. Every host port is overridable through `.env`.

| Service | Image | Host port | Container port | Compose profile |
| --- | --- | --- | --- | --- |
| `nginx` | `nginx:1.31.4-alpine` | `HTTP_PORT` (8081) | 80 | always on |
| `php` | built from `docker/php/Dockerfile` | — | 9000 (FPM, internal) | always on |
| `db` | `mariadb:11.4.12` | — | 3306 (internal) | always on |
| `adminer` | `adminer:6.0.1-standalone` | `ADMINER_PORT` (8082) | 8080 | `tools` |
| `mailpit` | `axllent/mailpit:v1.31.0` | `MAILPIT_PORT` (8025) | 8025 | `tools` |
| `sass` | same image as `php` | — | — | `dev` |

`.env.example` ships with `COMPOSE_PROFILES=tools`, so Adminer and Mailpit start together with the
core three. Add `dev` to that variable (`COMPOSE_PROFILES=tools,dev`) if you want the Sass watcher to
come up with `make up` as well; otherwise start it on demand with `make scss-watch`.

Mailpit's SMTP listener on port 1025 is never published to the host — only the web inbox is. The
database keeps its data in the named volume `db_data`, which survives `make down` and is deleted by
`make destroy`.

### What is inside the stack

Versions observed on a running site:

| Component | Version |
| --- | --- |
| Drupal core | 11.4.5 |
| PHP | 8.4.24 (FPM, Debian trixie base) |
| MariaDB | 11.4.12 |
| Nginx | 1.31.4-alpine |
| Drush | 13.7.6.0 |
| Composer | 2.10.2 |
| Dart Sass | 1.103.1 |
| Bootstrap | 5.3.8, served from `/libraries/bootstrap/dist` |
| Xdebug | 3.5.3 |
| Adminer | 6.0.1-standalone |
| Mailpit | v1.31.0 |

The PHP image also builds the `gd` (with AVIF, WebP, JPEG and FreeType), `pdo_mysql` and `zip`
extensions, and installs the MariaDB client so the installer can wait for the database.

---

## Command reference

Run `make help` for the same list, generated from the Makefile itself.

### Setup

| Target | Description |
| --- | --- |
| `make init` | Create `.env` from `.env.example` when it does not exist yet |
| `make install` | Full setup: env file, images, containers and Drupal installation |
| `make reinstall` | Reinstall Drupal from scratch, dropping the current database |

### Containers

| Target | Description |
| --- | --- |
| `make build` | Build the application images |
| `make up` | Start the containers in the background |
| `make down` | Stop the containers, keeping the database volume |
| `make destroy` | Stop the containers and delete their volumes |
| `make ps` | Show the status of the containers |
| `make logs` | Follow logs, optionally for one service: `make logs s=php` |
| `make shell` | Open a shell in the php container as `www-data` |

### Drupal

| Target | Description |
| --- | --- |
| `make drush ARGS="status"` | Run any Drush command |
| `make composer ARGS="require drupal/token"` | Run any Composer command |
| `make composer-install` | Install the PHP dependencies |
| `make cr` | Rebuild the Drupal caches |
| `make uli` | Print a one-time login link for the admin account |
| `make config-export` | Export the Drupal configuration |
| `make config-import` | Import the Drupal configuration |
| `make db-shell` | Open a MariaDB shell on the project database |

### Development

| Target | Description |
| --- | --- |
| `make xdebug-on` | Recreate the php container with Xdebug enabled |
| `make xdebug-off` | Recreate the php container with Xdebug disabled |
| `make twig-debug-on` | Enable Twig debugging and disable render caching |
| `make twig-debug-off` | Disable Twig debugging and restore render caching |
| `make scss` | Compile the theme SCSS once |
| `make scss-watch` | Watch the theme SCSS and recompile on every change |

---

## Project structure

Only 20 files are kept under version control. Everything else — Drupal core, contrib modules and
themes, `vendor/`, `web/libraries/`, the scaffold files, `settings.php` and the public files
directory — is generated by Composer or by the installer and is listed in `.gitignore`.

```
.editorconfig                 Drupal's editor normalisation rules
.env.example                  Documented template for the local .env
.gitignore                    Everything Composer and Drupal generate
Makefile                      The whole developer interface
README.md                     This document
compose.yaml                  Service definitions
composer.json                 Dependencies, installer paths, scaffold config
composer.lock                 Pinned dependency tree
config/sync/.gitkeep          Configuration sync directory
docker/nginx/default.conf     Nginx vhost with a PHP front-controller allow-list
docker/php/Dockerfile         PHP-FPM image: extensions, Composer, Dart Sass, Mailpit
docker/php/conf.d/zz-drupal.ini   PHP runtime settings for Drupal development
docker/php/conf.d/zz-xdebug.ini   Xdebug configuration, driven by env vars
scripts/install.sh            Idempotent Drupal installation
web/themes/custom/voting_theme/
    voting_theme.info.yml           Theme metadata, base theme, regions
    voting_theme.libraries.yml      Bootstrap and global-styling libraries
    css/style.css                   Compiled output, committed
    scss/style.scss                 SCSS source
    config/install/voting_theme.settings.yml   Default Barrio settings for this theme
    config/schema/voting_theme.schema.yml      Schema inherited from bootstrap_barrio.settings
```

The document root is `web/`, declared through `drupal-scaffold.locations.web-root` in
`composer.json`. `vendor/` sits outside it, and Nginx serves `/var/www/html/web` only.

---

## Configuration (`.env` reference)

`.env` is not versioned; `.env.example` is. Every value below is a development-only throwaway.

### Compose

| Key | Default | Purpose |
| --- | --- | --- |
| `COMPOSE_PROJECT_NAME` | `simple-voting-system` | Prefix for container, network and volume names, and for the built PHP image |
| `COMPOSE_PROFILES` | `tools` | Profiles started by default. `tools` adds Adminer and Mailpit; add `dev` for the Sass watcher |

### Ports (host side)

| Key | Default | Purpose |
| --- | --- | --- |
| `HTTP_PORT` | `8081` | Host port serving the Drupal site through Nginx |
| `ADMINER_PORT` | `8082` | Host port serving the Adminer database client |
| `MAILPIT_PORT` | `8025` | Host port serving the Mailpit web inbox |

### Host user mapping

| Key | Default | Purpose |
| --- | --- | --- |
| `APP_UID` | `1000` | Host user id owning the bind-mounted files |
| `APP_GID` | `1000` | Host group id used together with `APP_UID` |

The Makefile overrides both with the real `id -u` / `id -g` of whoever runs `make` and exports them
to Compose, so the values in `.env` only matter when you call `docker compose` directly.

### PHP and toolchain

| Key | Default | Purpose |
| --- | --- | --- |
| `PHP_VERSION` | `8.4` | Base image tag for the `php` service |
| `SASS_VERSION` | `1.103.1` | Dart Sass release compiled into the PHP image |
| `XDEBUG_MODE` | `off` | `off` for full speed, `debug` to step through code, `coverage` for reports |
| `XDEBUG_CLIENT_HOST` | `host.docker.internal` | Address Xdebug calls back to reach the IDE |

### Database

| Key | Default | Purpose |
| --- | --- | --- |
| `DB_HOST` | `db` | Hostname of MariaDB on the Compose network |
| `DB_NAME` | `drupal` | Database created on first boot |
| `DB_USER` | `drupal` | Application database user |
| `DB_PASSWORD` | `drupal` | Password for the application user |
| `DB_ROOT_PASSWORD` | `root` | MariaDB root password, administrative tasks only |

### Drupal install

| Key | Default | Purpose |
| --- | --- | --- |
| `DRUPAL_SITE_NAME` | `Simple Voting System` | Site name written during installation |
| `DRUPAL_ACCOUNT_NAME` | `admin` | First administrator account |
| `DRUPAL_ACCOUNT_PASS` | `admin` | Password for that account |
| `DRUPAL_ACCOUNT_MAIL` | `admin@example.com` | Email for that account |
| `DRUPAL_BASE_URL` | `http://localhost:8081` | Absolute site URL; also exported as `DRUSH_OPTIONS_URI` |
| `DRUPAL_INSTALL_PROFILE` | `standard` | Profile passed to `drush site:install` |

`DRUPAL_BASE_URL` does double duty: it is exported as `DRUSH_OPTIONS_URI` so Drush generates correct
absolute links, and the installer derives the `trusted_host_patterns` entry from it. The installer
strips the scheme and the port first, so the pattern is built from the **hostname alone** — changing
`HTTP_PORT` leaves `trusted_host_patterns` identical, and only a hostname change requires the
patterns to be rewritten.

---

## Development workflow

### Twig debugging

```bash
make twig-debug-on     # drush theme:dev on
make twig-debug-off    # drush theme:dev off
```

`on` adds `THEME DEBUG` comments to the HTML source, naming the active template and the candidate
template suggestions, and disables render and Twig caching. `off` restores the defaults. Confirmed in
practice — with debugging on, the page source shows entries such as
`BEGIN OUTPUT from 'themes/contrib/bootstrap_barrio/templates/layout/html.html.twig'`, which is also
the quickest way to prove Barrio is actually rendering the page.

### Xdebug

Xdebug 3.5.3 is compiled into the image but starts disabled, so there is no cost when you are not
using it. `xdebug.start_with_request` is set to `trigger`, meaning a session only starts when the
request carries the trigger (a browser extension, or `XDEBUG_TRIGGER` / `XDEBUG_SESSION` in the query
string, cookie or environment).

```bash
make xdebug-on     # recreates php with XDEBUG_MODE=debug, then restarts nginx
make xdebug-off    # back to XDEBUG_MODE=off
```

The IDE is expected on `host.docker.internal:9003` with the IDE key `PHPSTORM`. Only the callback
host is configurable through `.env`, via `XDEBUG_CLIENT_HOST`; the port and the IDE key are fixed in
`docker/php/conf.d/zz-xdebug.ini` and changing them means editing that file and rebuilding the image.
Nginx allows `fastcgi_read_timeout 600s` on the PHP location precisely so a paused breakpoint does
not produce a 504.

The `make` targets restart Nginx as their last step for a reason — see the 502 note under
[Troubleshooting](#troubleshooting).

### SCSS

The theme's source is `web/themes/custom/voting_theme/scss/style.scss` and the compiled result,
`css/style.css`, is committed so the theme works without a build step.

```bash
make scss          # one-off compile
make scss-watch    # sass --watch, recompiles on save
```

Both run Dart Sass in the `sass` service with `--no-source-map --no-error-css --style=compressed`.
`--no-error-css` is the important flag: when the SCSS fails to parse, Sass exits non-zero and writes
nothing, instead of overwriting `style.css` with a stylesheet whose only job is to display the error
in the browser. A deliberate syntax error was used to verify this — `make scss` exited with status 2
and `css/style.css` was left untouched.

The watcher runs with `--poll`, which is what makes file-change detection reliable across a bind
mount.

### Mail

PHP's `sendmail_path` is set to Mailpit's `sendmail` shim, pointed at `mailpit:1025`. Anything Drupal
sends — the one-time login link, password resets, contact forms — is captured instead of delivered
and shows up at <http://localhost:8025>. Verified end to end: a message sent through Drupal's mail
manager was found in Mailpit's API by its subject.

### Database

```bash
make db-shell      # MariaDB CLI on the project database
```

Or use Adminer at <http://localhost:8082>. It is pre-pointed at the `db` server; log in with
`DB_USER` / `DB_PASSWORD` and select `DB_NAME`.

---

## Configuration management

`config/sync` is the export directory, declared by the installer as
`$settings['config_sync_directory'] = '../config/sync';` in `settings.php` — outside the document
root, and tracked in git through `config/sync/.gitkeep`.

```bash
make config-export     # drush config:export -y
make config-import     # drush config:import -y
```

The directory is intentionally empty for now. The installer configures the site imperatively through
Drush (`theme:install`, `config:set system.theme`, `pm:install gin_toolbar`) rather than by importing
a full configuration snapshot, which keeps a fresh install reproducible without pinning a site UUID.
Once the voting feature exists, its content types, fields, views and permissions belong in
`config/sync`, exported and reviewed like code.

Drupal writes an `.htaccess` file into `config/sync` to protect it; that generated file is
gitignored.

---

## Architecture and design decisions

### Composer-managed everything, `web/` as document root

`composer.json` follows the `drupal/recommended-project` layout: core, contrib and libraries are
installed through `composer/installers` into `web/`, and `vendor/` stays a sibling of the document
root rather than a child of it. `composer.lock` is committed, so every developer and every CI run
resolves the exact same tree.

Bootstrap is a special case. `twbs/bootstrap` is a plain npm-style package, not a Drupal library, so
a Composer script copies its `dist/` into `web/libraries/bootstrap/dist/` on every
`post-install-cmd` and `post-update-cmd`. The installer verifies both files exist and aborts with an
explicit message if they do not — a missing Bootstrap produces no PHP error and no log entry, only a
silent 404 and an unstyled page, which is a bad thing to discover by eye.

### The theme attaches Bootstrap itself

`voting_theme` declares its own `bootstrap` library in `voting_theme.libraries.yml`, pointing at the
local files under `/libraries/bootstrap/dist`, and lists it in `libraries:` in the `.info.yml`. It
deliberately does **not** use Bootstrap Barrio's "Load library" theme setting.

This is worth spelling out, because it is a real trap on Drupal 11. Barrio only attaches Bootstrap
through its own setting when the `bootstrap_library` *module* is installed — and that module has no
Drupal 11 release. Without it, Barrio falls back to the `bootstrap_barrio_source` theme setting,
which ships with no default value. The outcome is a site with no Bootstrap at all, no error, and
nothing in the logs. Declaring the library in the sub-theme sidesteps the entire mechanism: the
assets are attached by Drupal's ordinary library system and their presence is verified at install
time. As a bonus, Barrio's CDN option is pinned to Bootstrap 5.2.0 (2022); this project serves 5.3.8
locally, with no CDN dependency anywhere in the page.

With aggregation disabled, the rendered page references exactly three stylesheets and scripts of its
own: `/libraries/bootstrap/dist/css/bootstrap.min.css`,
`/libraries/bootstrap/dist/js/bootstrap.bundle.min.js` and
`themes/custom/voting_theme/css/style.css`. No CDN host is contacted by the rendered page, and none
of the versioned files contains a CDN URL. Barrio's own `bootstrap_cdn` and Bootswatch library
definitions in `web/themes/contrib/bootstrap_barrio/bootstrap_barrio.libraries.yml` still carry
`cdn.jsdelivr` URLs, as does the vendored `twbs/bootstrap` documentation source — they are simply
never attached.

### Bootstrap Barrio's release cadence

Barrio's most recent release, 5.5.20 (June 2025), predates Drupal 11.2, 11.3 and 11.4. It declares
`core_version_requirement: ^10.3 || ^11.0`, installs cleanly on 11.4.5 and renders correctly in
manual smoke testing, and `drush core:requirements --severity=2` reports zero errors. One concrete
consequence is already handled: Barrio calls the deprecated `theme_get_setting()` on every page
render, so `zz-drupal.ini` sets `error_reporting = E_ALL & ~E_DEPRECATED` to keep the notice out of
the way without silencing real errors. This is a known consideration to keep an eye on, not a
blocker.

### Gin for the admin theme

`gin` is the admin theme and `gin_toolbar` is enabled, with
`node.settings.use_admin_theme` set to `true` so node forms use it too. `olivero` and `claro` remain
installed but inactive, which keeps a fallback available.

### One image for PHP and Sass

The `sass` service reuses the `php` image rather than pulling a Node toolchain. Dart Sass is a single
static binary; installing it in the PHP image costs one layer and removes an entire ecosystem from
the stack. The service simply overrides the command, the working directory and the user.

### Front-controller allow-list in Nginx

Instead of denying dangerous paths one by one, `docker/nginx/default.conf` hands only five PHP
entry points to PHP-FPM — `/index.php`, `/update.php`, `/core/authorize.php`, `/core/install.php`
and `/core/rebuild.php` — and returns 404 for every other `.php` request. Verified:

| Request | Result |
| --- | --- |
| `/` | 200 |
| `/user/login` | 200 |
| `/core/install.php` | 200 |
| `/autoload.php` | 404 |
| `/vendor/autoload.php` | 404 |

The vhost also blocks dotfiles, `/vendor/`, private file directories, PHP files uploaded into
`sites/*/files/`, and source extensions such as `.yml`, `.twig`, `.module`, `.sql` and `.log`.
`nginx -t` reports the configuration valid.

### Host user remapping

The image remaps `www-data` to the host's UID and GID at build time (`APP_UID` / `APP_GID`), and the
Makefile fills those from `id -u` and `id -g`. Every command that touches project files —
`composer`, `drush`, the installer, the Sass watcher — runs as that user. After a complete install
there are zero root-owned files in the working tree, so you never need `sudo` to edit or delete
generated code.

### Idempotent installer

`scripts/install.sh` is written to be re-runnable rather than one-shot:

- it waits for MariaDB with a bounded retry loop (60 attempts, 2 seconds apart) instead of assuming
  the healthcheck is enough;
- it runs `composer install` when `composer.lock` exists and `composer update` when it does not, so
  a first run on a lock-less checkout still works;
- it checks that the Bootstrap assets and the theme's `.info.yml` are present before touching Drupal;
- it skips `site:install` when `drush status` reports a successful bootstrap, unless
  `FORCE_INSTALL=1` is set;
- it appends to `settings.php` only after matching the **literal** line it is about to write. A
  keyword match would find `default.settings.php`'s own commented documentation of those keys and
  skip the real write. It restores the file's original read-only mode afterwards.

A second consecutive run leaves the site and `settings.php` unchanged.

---

## Security notes

**This is a development environment. It is not hardened and must never be exposed to a public
network.** Specifically:

- Every credential in `.env.example` is a throwaway (`drupal`/`drupal`, `admin`/`admin`, root
  password `root`).
- `display_errors` and `display_startup_errors` are `On`.
- `opcache.validate_timestamps = 1` with `revalidate_freq = 0` — PHP re-checks every file on every
  request, which is what you want while editing and the opposite of what you want in production.
- Adminer and Mailpit are published on the host with no authentication in front of them.
- Xdebug is present in the image, even though it is off by default.

What is already right, and should stay right: the PHP front-controller allow-list, `vendor/` outside
the document root, `trusted_host_patterns` written at install time (a request with
`Host: evil.example.com` is answered with 400), `.env` and `settings.php` excluded from version
control, and a database that is never published to the host.

For a production deployment the changes would be: real credentials from a secret store,
`display_errors = Off`, `opcache.validate_timestamps = 0`, no Xdebug in the image, no Adminer and no
Mailpit, HTTPS with HSTS in front of Nginx, `composer install --no-dev --optimize-autoloader`, and
file permissions that make `settings.php` and the code tree read-only to the web user.

---

## Troubleshooting

### `docker compose build` fails with "Temporary failure resolving 'deb.debian.org'"

The symptom is apt exiting with code 100 during the image build, while `docker run` on the same host
resolves DNS perfectly well. The cause is specific and worth knowing: on Linux hosts where
`/etc/resolv.conf` points at the systemd-resolved stub resolver (`127.0.0.53`), BuildKit copies that
file into the build sandbox, where `127.0.0.53` is a dead end.

Two fixes. The first needs no privileges:

**(a) Build on the host network.** Create `compose.override.yaml` in the project root — it is already
in `.gitignore` for exactly this purpose, so it stays a local, machine-specific file:

```yaml
services:
  php:
    build:
      network: host
  sass:
    build:
      network: host
```

Then `make build` again.

**(b) Give the Docker daemon explicit DNS servers.** Edit `/etc/docker/daemon.json`:

```json
{
  "dns": ["1.1.1.1", "8.8.8.8"]
}
```

```bash
sudo systemctl restart docker
```

### "port is already allocated"

Another process — often a second Docker stack — holds 8081, 8082 or 8025. Change `HTTP_PORT`,
`ADMINER_PORT` or `MAILPIT_PORT` in `.env` and run `make up` again.

If you change `HTTP_PORT`, update `DRUPAL_BASE_URL` to match and recreate the `php` container so
`DRUSH_OPTIONS_URI` picks up the new value:

```bash
docker compose up -d --force-recreate php
```

No reinstall is needed. `trusted_host_patterns` is derived from the hostname only, so a port change
does not affect it — `make reinstall` would drop and recreate the site for nothing. Reinstall only
when the **hostname** in `DRUPAL_BASE_URL` changes.

### 502 Bad Gateway after recreating the php container

Nginx resolves `php` once at startup and caches the address. When Compose recreates the `php`
container it usually gets a new IP, and Nginx keeps talking to the old one.

```bash
docker compose restart nginx
```

`make xdebug-on` and `make xdebug-off` already do this for you; you only hit it when you recreate
`php` by hand.

### `composer install` fails on the first run

The first dependency resolution needs `repo.packagist.org` for the general packages and
`packages.drupal.org` for the `drupal/*` ones. Check the container's network access and retry:

```bash
make composer-install
```

### The site loads without styling

Check that the Bootstrap assets were copied out of `vendor/`:

```bash
make shell
ls web/libraries/bootstrap/dist/css/bootstrap.min.css
composer run-script install-bootstrap-library   # if it is missing
```

### Checking the site's health

```bash
make drush ARGS="core:requirements --severity=2"
```

A healthy install returns zero errors. Two warnings are expected and benign: "HTML5 validation:
Enabled" and "Drupal core update status: No update data available" (the latter simply means the
update module has not fetched release data yet).

---

## Out of scope and next steps

### Not in this repository

- **The voting feature.** No content type, no entity, no custom module, no business logic. The
  environment is the deliverable at this stage.
- **Versioned tests.** This project follows the convention that Drupal work does not commit tests;
  testing is done locally during development and the test code is removed before committing. The
  absence of a `tests/` directory is deliberate, not an oversight.
- **CI, deployment and production configuration.** See [Security notes](#security-notes) for what
  would have to change first.

### Planned next

A REST API for the voting feature, implemented **without** Drupal core's `rest` or `jsonapi`
modules — plain routed controllers returning `JsonResponse` — accompanied by an OpenAPI/Swagger
documentation page. None of it exists yet; it is described here so
the direction is clear, not to suggest it is available.
