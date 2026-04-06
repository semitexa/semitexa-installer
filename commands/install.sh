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
cp /installer/scaffold/docker-compose.ollama.yml           /app/docker-compose.ollama.yml
cp /installer/scaffold/docker-compose.override.yml.example /app/docker-compose.override.yml.example
cp /installer/scaffold/.env.default                        /app/.env.default
cp /installer/scaffold/.gitignore                          /app/.gitignore

mkdir -p /app/bin
mkdir -p /app/scripts
cp /installer/scaffold/bin/semitexa /app/bin/semitexa
cp /installer/scaffold/scripts/bootstrap-project.sh /app/scripts/bootstrap-project.sh

cat <<'EOF' > /app/.env
# Local overrides for Semitexa.
# Keep this file uncommitted.
# Add machine-specific values here when you need them.
EOF

success "Scaffold files written."

# ── 6. Fix permissions & ownership ──────────────────────────────────────────
chmod +x /app/bin/semitexa /app/scripts/bootstrap-project.sh

# Fix ownership to match the host user who owns /app.
#
# Detection order:
#   1. INSTALL_UID / INSTALL_GID env vars (explicit override)
#   2. stat /app   — works for a freshly created, user-owned directory
#   3. stat /app/.. — fallback when /app itself is root-owned (e.g. from a
#      previous installer run that had this same bug)
#
#   When the result is still 0 (rootless Docker — container UID 0 IS the
#   host user) chown 0:0 is a no-op and files are already correctly owned.
#
_uid="${INSTALL_UID:-$(stat -c '%u' /app 2>/dev/null || echo 0)}"
_gid="${INSTALL_GID:-$(stat -c '%g' /app 2>/dev/null || echo 0)}"

if [ "$_uid" -eq 0 ] && [ -z "${INSTALL_UID:-}" ]; then
    _parent_uid="$(stat -c '%u' /app/.. 2>/dev/null || echo 0)"
    if [ "$_parent_uid" -ne 0 ]; then
        _uid="$_parent_uid"
        _gid="$(stat -c '%g' /app/.. 2>/dev/null || echo 0)"
    fi
fi

chown "${_uid}:${_gid}" \
    /app \
    /app/bin \
    /app/Dockerfile \
    /app/docker-compose.yml \
    /app/docker-compose.ollama.yml \
    /app/docker-compose.override.yml.example \
    /app/.env.default \
    /app/.env \
    /app/.gitignore \
    /app/bin/semitexa \
    /app/scripts \
    /app/scripts/bootstrap-project.sh

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

printf "AI assistant (optional):\n\n"
printf "  Enable Ollama LLM by copying the LLM_* settings from .env.default to .env and uncommenting them, then:\n"
printf "       ${C_CYAN}docker compose -f docker-compose.yml -f docker-compose.ollama.yml up -d${C_RESET}\n"
printf "       ${C_CYAN}docker compose exec ollama ollama pull gemma3:4b${C_RESET}\n"
printf "       ${C_CYAN}./bin/semitexa php bin/semitexa ai${C_RESET}\n\n"
printf "  The standard ${C_CYAN}bin/semitexa server:start${C_RESET} flow does not enable Ollama automatically.\n\n"

printf "Documentation: ${C_CYAN}https://semitexa.dev/docs${C_RESET}\n\n"
