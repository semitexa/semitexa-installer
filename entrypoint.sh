#!/bin/sh
set -e

COMMAND="${1:-help}"
shift || true

case "$COMMAND" in
    install)
        exec sh /installer/commands/install.sh "$@"
        ;;
    help|--help|-h)
        cat <<'EOF'
Semitexa Ultimate Installer

Usage:
  docker run --rm -v $(pwd):/app semitexa/installer <command>

Commands:
  install [--force]   Scaffold a new Semitexa project into the current directory
  help                Show this help

Example:
  mkdir my-project && cd my-project
  docker run --rm -v $(pwd):/app semitexa/installer install
  docker compose up -d
EOF
        ;;
    *)
        echo "[ERROR] Unknown command: $COMMAND" >&2
        echo "Run with 'help' to see available commands." >&2
        exit 1
        ;;
esac
