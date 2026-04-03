# Semitexa Installer

Docker-based project scaffolder that bootstraps a new Semitexa application with a single command.

## Purpose

Packages the project scaffold and installation logic into a standalone Docker image. Running the container against an empty directory produces a ready-to-run Semitexa project with Docker Compose configuration, environment defaults, and application entrypoints.

## Role in Semitexa

Standalone tool with no runtime dependency on other Semitexa packages. Produces a project skeleton that pulls in `semitexa/core` and other packages via Composer.

## Key Features

- Single-command project scaffolding via `docker run`
- Pre-built Docker Compose configurations (base, DNS, Ollama)
- Environment template with sensible defaults
- `--force` flag for overwriting existing scaffolds
- Alpine-based minimal Docker image
