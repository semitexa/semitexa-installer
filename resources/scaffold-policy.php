<?php

declare(strict_types=1);

/*
 * The policy of every file in scaffold/: what semitexa/update does with it in a
 * project (category, whether it is critical, replaced automatically, kept
 * executable) and the note it shows. tools/build-manifest.php writes the
 * manifest from it, and tools/check-manifest.php refuses a scaffold file with no
 * row or an entry whose flags differ from it.
 *
 * Plain PHP with no autoloader (step 2.5 of one source for bin/semitexa), so
 * the tools run anywhere PHP does. The categories are strings from the reader's
 * vocabulary (semitexa/update ScaffoldFileCategory): a new one needs a reader
 * that knows it first, see check-manifest.php CATEGORIES.
 *
 * @return array<string, array{category: string, critical: bool, auto_update: bool, preserve_executable: bool, notes: string}>
 */

return [
    '.claude/skills/make-it/SKILL.md' => [
        'category' => 'agent_skill',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Thin skill: it only keeps asking ai:make-it. Created when missing; a project that edited it gets the new version as .new to merge.',
    ],
    '.env.default' => [
        'category' => 'env_template',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Template only. The project .env file is never touched by scaffold sync.',
    ],
    '.gitignore' => [
        'category' => 'vcs',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'Dockerfile' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'bin/semitexa' => [
        'category' => 'executable',
        'critical' => true,
        'auto_update' => true,
        'preserve_executable' => true,
        'notes' => 'Project CLI entry point. Auto-replace only when the project file matches a recorded prior hash; preserve +x; backup before replace.',
    ],
    'docker-compose.e2e.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Points the e2e-runner at the E2E phase\'s own app container (tk-e2e-own-database). Only overrides a service docker-compose.test.yml defines; bin/semitexa test:run adds it to the file set for that run.',
    ],
    'docker-compose.mysql.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker-compose.nats.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker-compose.ollama.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker-compose.override.yml.example' => [
        'category' => 'example',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Template only. The project-managed docker-compose.override.yml is never touched.',
    ],
    'docker-compose.redis.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker-compose.test.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker-compose.worker.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'The worker service, which runs what worker:processes lists. Self-contained: no other compose file names it, so a project keeping an older edited docker-compose.yml still gets a valid stack.',
    ],
    'docker-compose.yml' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => '',
    ],
    'docker/e2e-runner/Dockerfile' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Image for the e2e-runner service in docker-compose.test.yml. Its FROM tag and the pinned @playwright/test version move together.',
    ],
    'phpstan-bootstrap.php' => [
        'category' => 'analysis',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Registers local-module PSR-4 for the analyser. Framework-owned; named by phpstan.neon\'s bootstrapFiles, so PHPStan refuses to start without it.',
    ],
    'phpstan-strict.neon' => [
        'category' => 'analysis',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'Two lines wrapping phpstan.neon with reportUnmatchedIgnoredErrors. Framework-owned.',
    ],
    'phpstan.neon' => [
        'category' => 'analysis',
        'critical' => false,
        'auto_update' => false,
        'preserve_executable' => false,
        'notes' => 'The project owns this: it adds paths and uncomments its baseline include. An update offers a candidate as .new rather than replacing it.',
    ],
    'playwright.config.ts' => [
        'category' => 'infrastructure',
        'critical' => false,
        'auto_update' => true,
        'preserve_executable' => false,
        'notes' => 'What the e2e-runner runs: specs under src/modules/*/tests/E2E. Created when missing; a project that edited it keeps its own.',
    ],
];
