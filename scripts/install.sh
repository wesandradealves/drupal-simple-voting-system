#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${LANDO_MOUNT:-/app}"
DRUSH="vendor/bin/drush"
SETTINGS_FILE="web/sites/default/settings.php"
FILES_DIR="web/sites/default/files"
BOOTSTRAP_CSS="web/libraries/bootstrap/dist/css/bootstrap.min.css"
BOOTSTRAP_JS="web/libraries/bootstrap/dist/js/bootstrap.bundle.min.js"
MODULE_NAME="drupal_simple_voting"
MODULE_INFO="web/modules/custom/${MODULE_NAME}/${MODULE_NAME}.info.yml"
CONFIG_SYNC_LINE="\$settings['config_sync_directory'] = '../config/sync';"
DB_MAX_ATTEMPTS=60
DB_RETRY_DELAY=2

: "${DB_HOST:=database}"
: "${DB_NAME:=drupal}"
: "${DB_USER:=drupal}"
: "${DB_PASSWORD:=drupal}"
: "${DRUPAL_SITE_NAME:=Simple Voting System}"
: "${DRUPAL_ACCOUNT_NAME:=admin}"
: "${DRUPAL_ACCOUNT_PASS:=admin}"
: "${DRUPAL_ACCOUNT_MAIL:=admin@example.com}"
: "${DRUPAL_INSTALL_PROFILE:=minimal}"
: "${DRUPAL_BASE_URL:=https://sistema-de-votacao.lndo.site}"
: "${FORCE_INSTALL:=0}"

log_step() {
  printf '\n==> %s\n' "$1"
}

log_detail() {
  printf '    %s\n' "$1"
}

abort() {
  printf '\nERROR: %s\n' "$1" >&2
  exit 1
}

resolve_db_client() {
  local candidate
  for candidate in mariadb mysql; do
    if command -v "$candidate" >/dev/null 2>&1; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  return 1
}

wait_for_database() {
  local client="$1"
  local attempt=1
  while [ "$attempt" -le "$DB_MAX_ATTEMPTS" ]; do
    if "$client" -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e 'SELECT 1' >/dev/null 2>&1; then
      return 0
    fi
    log_detail "MariaDB is not ready yet, attempt ${attempt}/${DB_MAX_ATTEMPTS}"
    sleep "$DB_RETRY_DELAY"
    attempt=$((attempt + 1))
  done
  return 1
}

is_site_installed() {
  local bootstrap
  bootstrap="$("$DRUSH" status --field=bootstrap 2>/dev/null || true)"
  case "$bootstrap" in
    *Successful*) return 0 ;;
    *) return 1 ;;
  esac
}

cd "$PROJECT_ROOT"

log_step "Waiting for the database at ${DB_HOST}"
DB_CLIENT="$(resolve_db_client)" || abort "No MariaDB client binary is available in this container."
wait_for_database "$DB_CLIENT" \
  || abort "MariaDB did not accept connections within $((DB_MAX_ATTEMPTS * DB_RETRY_DELAY)) seconds."
log_detail "Database ${DB_NAME} is accepting connections."

log_step "Installing the PHP dependencies"
# The lock file is not versioned on the first run of a fresh clone, and "composer install"
# refuses to run without it, so the first run resolves the tree and writes the lock instead.
if [ -f composer.lock ]; then
  composer install --no-interaction --no-progress
else
  log_detail "No composer.lock found, resolving dependencies and generating it."
  composer update --no-interaction --no-progress
fi

log_step "Verifying the Bootstrap library assets"
if [ ! -f "$BOOTSTRAP_CSS" ] || [ ! -f "$BOOTSTRAP_JS" ]; then
  log_detail "Assets are missing, running the install-bootstrap-library script."
  composer run-script install-bootstrap-library
fi
[ -f "$BOOTSTRAP_CSS" ] || abort "Missing ${BOOTSTRAP_CSS}. The module would load without Bootstrap and the browser would only report a silent 404."
[ -f "$BOOTSTRAP_JS" ] || abort "Missing ${BOOTSTRAP_JS}. The module would load without Bootstrap and the browser would only report a silent 404."
log_detail "Bootstrap CSS and JS are in place."

log_step "Verifying the voting module"
[ -f "$MODULE_INFO" ] || abort "Missing ${MODULE_INFO}."
log_detail "${MODULE_NAME} is present."

log_step "Preparing the site directories"
mkdir -p "$FILES_DIR"
[ -w "web/sites/default" ] || chmod u+w "web/sites/default" 2>/dev/null || true
[ -w "$FILES_DIR" ] || chmod u+w "$FILES_DIR" 2>/dev/null || true
[ -w "web/sites/default" ] || abort "web/sites/default is not writable by $(id -un)."
[ -w "$FILES_DIR" ] || abort "${FILES_DIR} is not writable by $(id -un)."
log_detail "web/sites/default and ${FILES_DIR} are writable."

log_step "Installing Drupal"
if is_site_installed && [ "$FORCE_INSTALL" != "1" ]; then
  log_detail "The site is already installed, skipping site:install."
  log_detail "Run this script with FORCE_INSTALL=1 to drop the database and reinstall."
else
  if [ "$FORCE_INSTALL" = "1" ]; then
    log_detail "FORCE_INSTALL=1: the current database will be dropped and recreated."
  fi
  "$DRUSH" site:install "$DRUPAL_INSTALL_PROFILE" --yes \
    --db-url="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}/${DB_NAME}" \
    --site-name="$DRUPAL_SITE_NAME" \
    --account-name="$DRUPAL_ACCOUNT_NAME" \
    --account-pass="$DRUPAL_ACCOUNT_PASS" \
    --account-mail="$DRUPAL_ACCOUNT_MAIL"
fi

log_step "Removing the node module"
# The brief forbids node for the voting entities, but the minimal profile still installs it.
# Removing it here is what keeps a clean-room install identical to the committed state.
if "$DRUSH" pm:list --status=enabled --format=list 2>/dev/null | grep -qx 'node'; then
  "$DRUSH" pm:uninstall node -y
  log_detail "node uninstalled."
else
  log_detail "node is not installed, nothing to remove."
fi

append_setting_once() {
  line="$1"
  label="$2"
  # The literal line is the marker: default.settings.php documents these same keys in comments,
  # so a keyword match would find the documentation and skip the real write.
  if grep -qF "$line" "$SETTINGS_FILE"; then
    log_detail "${label} is already declared, leaving settings.php untouched."
    return 0
  fi
  # The installer hardens settings.php to read-only, so ownership is used to reopen it and the
  # original mode is restored right after the append.
  settings_mode="$(stat -c '%a' "$SETTINGS_FILE")"
  chmod u+w "$SETTINGS_FILE"
  printf '\n%s\n' "$line" >> "$SETTINGS_FILE"
  chmod "$settings_mode" "$SETTINGS_FILE"
}

log_step "Declaring the configuration sync directory"
[ -f "$SETTINGS_FILE" ] || abort "Missing ${SETTINGS_FILE}."
append_setting_once "$CONFIG_SYNC_LINE" "config_sync_directory"
log_detail "Sync directory set to ../config/sync."

log_step "Declaring trusted host patterns"
trusted_host="$(printf '%s' "$DRUPAL_BASE_URL" | sed -E 's#^[a-z]+://##; s#[:/].*$##; s#\.#\\.#g')"
append_setting_once \
  "\$settings['trusted_host_patterns'] = ['^${trusted_host}\$', '^127\\.0\\.0\\.1\$'];" \
  "trusted_host_patterns"
log_detail "Trusted hosts: ${trusted_host}, 127.0.0.1"

log_step "Installing the voting module"
"$DRUSH" pm:install "$MODULE_NAME" -y
if "$DRUSH" pm:list --status=enabled --format=list 2>/dev/null | grep -qx "$MODULE_NAME"; then
  log_detail "${MODULE_NAME} is enabled."
else
  abort "${MODULE_NAME} did not enable. The site would boot without the voting feature and its /polls front page."
fi

log_step "Installing and selecting the themes"
"$DRUSH" theme:install olivero -y
"$DRUSH" theme:install gin -y
"$DRUSH" config:set system.theme default olivero -y
"$DRUSH" config:set system.theme admin gin -y
# The minimal profile leaves the administration gutted: no Views, no field UI,
# no menu management, no block types. A reviewer opening this site would find
# half of Drupal missing. This is the standard profile's module set minus node
# and taxonomy — node because the brief forbids it, taxonomy because it depends
# on node and would drag it back in.
"$DRUSH" pm:install \
  field_ui views views_ui menu_ui menu_link_content block_content \
  path config contextual help options datetime link \
  editor ckeditor5 big_pipe automated_cron announcements_feed -y

"$DRUSH" pm:install gin_toolbar -y

log_step "Rebuilding the caches"
"$DRUSH" cache:rebuild

log_step "Site status"
"$DRUSH" status

log_step "Active theme configuration"
"$DRUSH" config:get system.theme

log_step "One-time login link"
"$DRUSH" user:login --no-browser

printf '\nSetup finished.\n'
