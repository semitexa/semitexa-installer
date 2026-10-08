# Semitexa Installer

Docker-based project scaffolder that bootstraps a new Semitexa application with a single command.

## Install a new project

The canonical way is the one-liner. It checks Docker, runs this installer image, registers the app with the local port broker and prints the next steps:

```bash
curl -fsSL https://semitexa.com/install.sh | bash -s my-project
cd my-project
bin/semitexa server:start
bin/semitexa orm:sync
```

You can also run the image directly against an empty directory:

```bash
mkdir my-project && cd my-project
docker run --rm -v "$(pwd)":/app semitexa/installer install
./bin/semitexa server:start
```

Prerequisites: Docker with Compose v2 and a user in the `docker` group. No PHP or Composer on the host. Documentation: https://semitexa.com/docs

## Purpose

Packages the project scaffold and installation logic into a standalone Docker image. Running the container against an empty directory produces a ready-to-run Semitexa project with Docker Compose configuration, environment defaults, and application entrypoints.

## Role in Semitexa

Standalone tool with no runtime dependency on other Semitexa packages. Produces a project skeleton that pulls in `semitexa/core` and other packages via Composer.

## Key Features

- Single-command project scaffolding via `docker run`
- Docker Compose base configuration plus overlays for MySQL, Redis, NATS, Ollama and tests
- Environment template with sensible defaults
- `--force` flag for overwriting existing scaffolds
- Alpine-based minimal Docker image
