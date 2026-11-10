#!/bin/sh
# semitexa/installer — install command
# Scaffolds a new Semitexa Ultimate project into /app (host bind-mount).
#
# Exit codes:
#   0  Success
#   1  /app not mounted or not writable
#   2  Directory is non-empty and --force not set
#   3  Internal scaffold error
#   4  --force over a project whose locked semitexa/core is older than the
#      scaffold needs (run bin/semitexa update there instead), or whose
#      composer.lock cannot be read
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

# ── 2a. Guard the core the new Dockerfile needs ─────────────────────────────
# --force over an existing project replaces the Dockerfile but keeps its
# composer.lock. The Dockerfile pins a Swoole whose curl hook inflates gzip
# before the client sees it; only semitexa/core with core#195 turns that off.
# An older locked core, rebuilt on this Dockerfile, would lose the
# decompression-bomb cap. bin/semitexa update moves core and the Dockerfile
# together, so it is the way forward for such a project.
#   - a release older than MIN_CORE is refused;
#   - a dev-* core installed from git carries its commit time in the lock, and
#     one older than the fix (core 82eccf6, committed 2026-10-30T06:27:43Z) is
#     refused, as is one whose time is missing or unreadable; only a
#     path-repository core (the framework workspace, the live checkout) passes
#     unchecked;
#   - a lock that cannot be read is refused: a guard that cannot check must
#     not pass. A project with no lock yet has nothing installed to protect.
MIN_CORE=2026.10.31.0849
MIN_CORE_TIME=2026-10-30T06:27:43+00:00
if [ -e /app/composer.lock ] || [ -L /app/composer.lock ]; then
    _verdict="$(php -r '
        $lock = json_decode((string) @file_get_contents("/app/composer.lock"), true);
        if (!is_array($lock) || !is_array($lock["packages"] ?? null)) { echo "invalid"; exit; }
        foreach (array_merge($lock["packages"], is_array($lock["packages-dev"] ?? null) ? $lock["packages-dev"] : []) as $p) {
            if (!is_array($p) || ($p["name"] ?? "") !== "semitexa/core") { continue; }
            $v = ltrim((string) ($p["version"] ?? ""), "v");
            if (preg_match("/^[0-9]/", $v) === 1) { echo version_compare($v, $argv[1], "<") ? "old $v" : "ok"; exit; }
            if ((is_array($p["dist"] ?? null) ? ($p["dist"]["type"] ?? null) : null) === "path") { echo "ok"; exit; }
            $t = is_string($p["time"] ?? null) ? strtotime($p["time"]) : false;
            echo $t === false ? "old-dev $v (no readable commit time)" : ($t < strtotime($argv[2]) ? "old-dev $v " . $p["time"] : "ok");
            exit;
        }
        echo "ok";' "$MIN_CORE" "$MIN_CORE_TIME" 2>/dev/null || echo invalid)"
    case "$_verdict" in
        ok) ;;
        old\ *)
            error "This project locks semitexa/core ${_verdict#old }; the scaffold needs ${MIN_CORE} or later."
            error "Run 'bin/semitexa update' in the project instead: it upgrades core and the Dockerfile together."
            exit 4
            ;;
        old-dev\ *)
            error "This project locks semitexa/core ${_verdict#old-dev }: a development commit that cannot be shown to carry the fix the scaffold needs (core 82eccf6)."
            error "Run 'bin/semitexa update' in the project instead: it upgrades core and the Dockerfile together."
            exit 4
            ;;
        *)
            error "/app/composer.lock cannot be read, so the installed semitexa/core cannot be checked."
            error "Repair it (composer install) or remove it, then run the installer again."
            exit 4
            ;;
    esac
fi

# ── 3. Copy scaffold files ───────────────────────────────────────────────────
info "Scaffolding Semitexa Ultimate project..."

cp /installer/scaffold/Dockerfile                          /app/Dockerfile
cp /installer/scaffold/docker-compose.yml                  /app/docker-compose.yml
cp /installer/scaffold/docker-compose.mysql.yml            /app/docker-compose.mysql.yml
cp /installer/scaffold/docker-compose.redis.yml            /app/docker-compose.redis.yml
cp /installer/scaffold/docker-compose.nats.yml             /app/docker-compose.nats.yml
cp /installer/scaffold/docker-compose.ollama.yml           /app/docker-compose.ollama.yml
cp /installer/scaffold/docker-compose.worker.yml           /app/docker-compose.worker.yml
cp /installer/scaffold/docker-compose.test.yml             /app/docker-compose.test.yml
# The E2E phase's own app: test:run adds this file to point the runner at it.
cp /installer/scaffold/docker-compose.e2e.yml              /app/docker-compose.e2e.yml
# What test:run needs to run E2E tests: the runner's image is built here, not pulled.
mkdir -p /app/docker/e2e-runner
cp /installer/scaffold/docker/e2e-runner/Dockerfile        /app/docker/e2e-runner/Dockerfile
cp /installer/scaffold/playwright.config.ts                /app/playwright.config.ts
cp /installer/scaffold/docker-compose.override.yml.example /app/docker-compose.override.yml.example
cp /installer/scaffold/.env.default                        /app/.env.default
cp /installer/scaffold/.gitignore                          /app/.gitignore
# A folder holding only .claude passes the guard above: a skill of its own stays.
mkdir -p /app/.claude/skills/make-it
[ -e /app/.claude/skills/make-it/SKILL.md ] || cp /installer/scaffold/.claude/skills/make-it/SKILL.md /app/.claude/skills/make-it/SKILL.md

mkdir -p /app/bin
cp /installer/scaffold/bin/semitexa /app/bin/semitexa

# Generate a stable, per-installation SEMITEXA_APP_ID (UUIDv4). The host-side
# `bin/semitexa local-app:reconcile` (auto-invoked from `server:start`) uses
# this id to allocate a unique Swoole port from the shared workstation router
# registry, so multiple Semitexa projects on the same machine never collide.
_app_id=""
if command -v uuidgen >/dev/null 2>&1; then
    _app_id="$(uuidgen 2>/dev/null | tr '[:upper:]' '[:lower:]')"
elif [ -r /proc/sys/kernel/random/uuid ]; then
    _app_id="$(cat /proc/sys/kernel/random/uuid 2>/dev/null)"
fi
if [ -z "$_app_id" ]; then
    warn "Could not generate SEMITEXA_APP_ID inside the installer container."
    warn "It will be generated on first 'bin/semitexa server:start' instead."
fi

cat > /app/.env <<EOF
# Local overrides for Semitexa.
# Keep this file uncommitted.
# Add machine-specific values here when you need them.

# Stable per-installation id used by the shared local router to allocate a
# unique Swoole port for this project. Do not reuse across installs.
SEMITEXA_APP_ID=${_app_id}
EOF

success "Scaffold files written."

# ── 6. Fix permissions & ownership ──────────────────────────────────────────
chmod +x /app/bin/semitexa

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

chown -R "${_uid}:${_gid}" /app/.claude /app/docker
chown "${_uid}:${_gid}" \
    /app \
    /app/bin \
    /app/Dockerfile \
    /app/docker-compose.yml \
    /app/docker-compose.mysql.yml \
    /app/docker-compose.redis.yml \
    /app/docker-compose.nats.yml \
    /app/docker-compose.ollama.yml \
    /app/docker-compose.worker.yml \
    /app/docker-compose.test.yml \
    /app/docker-compose.e2e.yml \
    /app/playwright.config.ts \
    /app/docker-compose.override.yml.example \
    /app/.env.default \
    /app/.env \
    /app/.gitignore \
    /app/bin/semitexa

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
printf "       ${C_CYAN}./bin/semitexa server:start${C_RESET}\n"
printf "     (first run auto-installs Semitexa via Composer — may take a minute)\n\n"

printf "  2. Sync the database schema:\n"
printf "       ${C_CYAN}./bin/semitexa orm:sync${C_RESET}\n\n"

printf "  3. Open your app (server:start prints the exact URL):\n"
printf "       ${C_CYAN}http://localhost:9502${C_RESET}\n"
printf "     (default port; if 9502 is busy, a free port in 9501-9599 is picked and written to .env)\n\n"

printf "For a shell inside the container:\n"
printf "       ${C_CYAN}./bin/semitexa sh${C_RESET}\n\n"

printf "AI assistant (optional):\n\n"
printf "  Enable Ollama LLM by copying the LLM_* settings from .env.default to .env, uncommenting them, and setting ${C_CYAN}LLM_PROVIDER=ollama${C_RESET}.\n"
printf "  Then either start the stack with:\n"
printf "       ${C_CYAN}./bin/semitexa server:start${C_RESET}\n"
printf "  or run the overlay manually with:\n"
printf "       ${C_CYAN}docker compose -f docker-compose.yml -f docker-compose.ollama.yml up -d${C_RESET}\n"
printf "       ${C_CYAN}docker compose exec ollama ollama pull gemma3:4b${C_RESET}\n"
printf "       ${C_CYAN}./bin/semitexa ai${C_RESET}\n\n"
printf "  ${C_CYAN}./bin/semitexa server:start${C_RESET} includes the Ollama overlay automatically when ${C_CYAN}LLM_PROVIDER=ollama${C_RESET}.\n\n"

printf "Documentation: ${C_CYAN}https://semitexa.com/docs${C_RESET}\n\n"
