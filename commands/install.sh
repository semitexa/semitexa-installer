#!/bin/sh
# semitexa/installer — install command
# Scaffolds a new Semitexa Ultimate project into /app (host bind-mount).
#
# Exit codes:
#   0  Success
#   1  /app not mounted or not writable
#   2  Directory is non-empty and --force not set
#   3  Internal scaffold error
set -e

# ── Colour helpers ──────────────────────────────────────────────────────────
if [ -t 1 ]; then
    C_RESET='\033[0m'
    C_GREEN='\033[0;32m'
    C_YELLOW='\033[0;33m'
    C_RED='\033[0;31m'
    C_CYAN='\033[0;36m'
    C_BOLD='\033[1m'
else
    C_RESET='' C_GREEN='' C_YELLOW='' C_RED='' C_CYAN='' C_BOLD=''
fi

info()    { printf "${C_CYAN}[INFO]${C_RESET}  %s\n" "$*"; }
success() { printf "${C_GREEN}[OK]${C_RESET}    %s\n" "$*"; }
warn()    { printf "${C_YELLOW}[WARN]${C_RESET}  %s\n" "$*" >&2; }
error()   { printf "${C_RED}[ERROR]${C_RESET} %s\n" "$*" >&2; }

# ── Parse flags ─────────────────────────────────────────────────────────────
FORCE=0
for _arg in "$@"; do
    case "$_arg" in --force|-f) FORCE=1 ;; esac
done

# ── 1. Validate /app ─────────────────────────────────────────────────────────
if [ ! -d /app ]; then
    error "/app is not mounted."
    error "Run: docker run --rm -v \$(pwd):/app semitexa/installer install"
    exit 1
fi

if [ ! -w /app ]; then
    error "/app is not writable. Check host directory permissions."
    exit 1
fi

# ── 2. Guard against overwrite ───────────────────────────────────────────────
_non_hidden=$(find /app -maxdepth 1 -mindepth 1 -not -name '.*' 2>/dev/null | wc -l)
if [ "$_non_hidden" -gt 0 ] && [ "$FORCE" -eq 0 ]; then
    error "Directory is not empty. Use --force to overwrite."
    error "WARNING: --force will overwrite existing scaffold files."
    exit 2
fi

# ── 3. Copy scaffold files ───────────────────────────────────────────────────
info "Scaffolding Semitexa Ultimate project..."

cp /installer/scaffold/Dockerfile                          /app/Dockerfile
cp /installer/scaffold/docker-compose.yml                  /app/docker-compose.yml
cp /installer/scaffold/docker-compose.override.yml.example /app/docker-compose.override.yml.example
cp /installer/scaffold/.env.example                        /app/.env.example
cp /installer/scaffold/.gitignore                          /app/.gitignore

mkdir -p /app/bin
cp /installer/scaffold/bin/semitexa /app/bin/semitexa

success "Scaffold files written."

# ── 4. Generate secrets ──────────────────────────────────────────────────────
info "Generating secrets..."

APP_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
DB_PASSWORD="$(php -r 'echo bin2hex(random_bytes(16));')"
DB_ROOT_PASSWORD="$(php -r 'echo bin2hex(random_bytes(16));')"

# ── 5. Write .env from .env.example with secrets substituted ────────────────
sed \
    -e "s|APP_KEY=CHANGEME|APP_KEY=${APP_KEY}|" \
    -e "s|DB_PASSWORD=CHANGEME|DB_PASSWORD=${DB_PASSWORD}|" \
    -e "s|DB_ROOT_PASSWORD=CHANGEME|DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}|" \
    /installer/scaffold/.env.example > /app/.env

success ".env generated with fresh secrets."

# ── 6. Fix permissions & ownership ──────────────────────────────────────────
chmod +x /app/bin/semitexa

# Fix ownership to match the host user who owns /app
_uid="$(stat -c '%u' /app 2>/dev/null || echo 0)"
_gid="$(stat -c '%g' /app 2>/dev/null || echo 0)"

if [ "$_uid" -ne 0 ]; then
    chown -R "${_uid}:${_gid}" /app/Dockerfile \
        /app/docker-compose.yml \
        /app/docker-compose.override.yml.example \
        /app/.env.example \
        /app/.env \
        /app/.gitignore \
        /app/bin/semitexa
fi

success "File ownership set to ${_uid}:${_gid}."

# ── 7. Print success banner ──────────────────────────────────────────────────
printf "\n"
printf "${C_GREEN}${C_BOLD}"
printf "╔══════════════════════════════════════════════════════╗\n"
printf "║        Semitexa Ultimate — Installation Complete     ║\n"
printf "╚══════════════════════════════════════════════════════╝\n"
printf "${C_RESET}\n"

printf "Your project has been scaffolded in the current directory.\n\n"

printf "Next steps:\n\n"

printf "  1. Start the environment:\n"
printf "       ${C_CYAN}docker compose up -d${C_RESET}\n"
printf "     (first run auto-installs Semitexa via Composer — may take a minute)\n\n"

printf "  2. Run database migrations:\n"
printf "       ${C_CYAN}./bin/semitexa php bin/semitexa db:migrate${C_RESET}\n\n"

printf "  3. Open your app:\n"
printf "       ${C_CYAN}http://localhost:8080${C_RESET}\n\n"

printf "For a shell inside the container:\n"
printf "       ${C_CYAN}./bin/semitexa sh${C_RESET}\n\n"

printf "Documentation: ${C_CYAN}https://semitexa.dev/docs${C_RESET}\n\n"
