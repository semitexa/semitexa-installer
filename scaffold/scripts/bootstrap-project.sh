#!/bin/sh
set -eu

TARGET_DIR="/var/www/html"
TEMP_DIR="/tmp/semitexa-bootstrap"
BACKUP_DIR="/tmp/semitexa-bootstrap-backup"
MARKER_PATH="${TARGET_DIR}/var/install/bootstrap-complete.json"
LOG_PATH="${TARGET_DIR}/var/log/install-bootstrap.log"
PACKAGE_NAME="semitexa/ultimate"
BOOTSTRAP_VERSION="1"

if [ -t 1 ]; then
    C_RESET='\033[0m'
    C_GREEN='\033[0;32m'
    C_RED='\033[0;31m'
    C_CYAN='\033[0;36m'
else
    C_RESET='' C_GREEN='' C_RED='' C_CYAN=''
fi

timestamp() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log_line() {
    _line="$1"
    printf "%s\n" "$_line"

    if [ -d "$TARGET_DIR" ] && [ -w "$TARGET_DIR" ]; then
        mkdir -p "${TARGET_DIR}/var/log" 2>/dev/null || true
        printf "%s\n" "$_line" >> "$LOG_PATH" 2>/dev/null || true
    fi
}

info() {
    log_line "${C_CYAN}[BOOTSTRAP]${C_RESET} $*"
}

step() {
    log_line "${C_CYAN}[BOOTSTRAP][STEP $1/5]${C_RESET} $2"
}

success() {
    log_line "${C_GREEN}[BOOTSTRAP][OK]${C_RESET} $*"
}

fail() {
    log_line "${C_RED}[BOOTSTRAP][ERROR]${C_RESET} $*"
    cleanup
    exit 2
}

cleanup() {
    rm -rf "$TEMP_DIR" "$BACKUP_DIR"
}

require_bin() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "Required binary '$1' is missing in the setup container."
    fi
}

backup_installer_owned_files() {
    rm -rf "$BACKUP_DIR"
    mkdir -p "$BACKUP_DIR/bin" "$BACKUP_DIR/scripts"

    for _file in \
        ".env" \
        ".env.example" \
        ".gitignore" \
        "Dockerfile" \
        "docker-compose.yml" \
        "docker-compose.ollama.yml" \
        "docker-compose.override.yml.example"
    do
        if [ -e "${TARGET_DIR}/${_file}" ]; then
            mkdir -p "${BACKUP_DIR}/$(dirname "$_file")"
            cp -a "${TARGET_DIR}/${_file}" "${BACKUP_DIR}/${_file}"
        fi
    done

    if [ -e "${TARGET_DIR}/bin/semitexa" ]; then
        cp -a "${TARGET_DIR}/bin/semitexa" "${BACKUP_DIR}/bin/semitexa"
    fi

    if [ -e "${TARGET_DIR}/scripts/bootstrap-project.sh" ]; then
        cp -a "${TARGET_DIR}/scripts/bootstrap-project.sh" "${BACKUP_DIR}/scripts/bootstrap-project.sh"
    fi
}

restore_installer_owned_files() {
    for _path in \
        ".env" \
        ".env.example" \
        ".gitignore" \
        "Dockerfile" \
        "docker-compose.yml" \
        "docker-compose.ollama.yml" \
        "docker-compose.override.yml.example" \
        "bin/semitexa" \
        "scripts/bootstrap-project.sh"
    do
        if [ -e "${BACKUP_DIR}/${_path}" ]; then
            mkdir -p "${TARGET_DIR}/$(dirname "$_path")"
            cp -a "${BACKUP_DIR}/${_path}" "${TARGET_DIR}/${_path}"
        fi
    done
}

if [ ! -d "$TARGET_DIR" ]; then
    fail "Target directory '${TARGET_DIR}' does not exist."
fi

if [ ! -w "$TARGET_DIR" ]; then
    fail "Target directory '${TARGET_DIR}' is not writable."
fi

info "Bootstrap entrypoint started."

for _bin in composer php cp rm mkdir date; do
    require_bin "$_bin"
done

if [ -f "${TARGET_DIR}/composer.json" ] && [ -f "$MARKER_PATH" ]; then
    info "Bootstrap already completed. Marker found at ${MARKER_PATH}."
    exit 0
fi

cleanup

step "1" "create project skeleton"
composer create-project "$PACKAGE_NAME" "$TEMP_DIR" \
    --no-install \
    --no-dev \
    --no-interaction \
    --prefer-dist || fail "Composer create-project failed."

step "2" "configure Composer allow-plugins"
composer config \
    --no-plugins \
    --working-dir="$TEMP_DIR" \
    allow-plugins.semitexa/core true || fail "Failed to allow semitexa/core Composer plugin."

step "3" "install Composer dependencies"
composer install \
    --working-dir="$TEMP_DIR" \
    --no-dev \
    --no-interaction \
    --prefer-dist || fail "Composer install failed."

step "4" "sync project files"
backup_installer_owned_files
cp -a "${TEMP_DIR}/." "${TARGET_DIR}/" || fail "Failed to copy generated project into bind mount."
restore_installer_owned_files

step "5" "write success marker"
mkdir -p "${TARGET_DIR}/var/install"
printf '{\n  "status": "ok",\n  "installed_package": "%s",\n  "installed_at": "%s",\n  "bootstrap_version": %s\n}\n' \
    "$PACKAGE_NAME" \
    "$(timestamp)" \
    "$BOOTSTRAP_VERSION" > "$MARKER_PATH" || fail "Failed to write bootstrap marker."

cleanup
success "Bootstrap completed successfully."
