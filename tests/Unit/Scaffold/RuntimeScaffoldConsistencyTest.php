<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit\Scaffold;

use PHPUnit\Framework\TestCase;

final class RuntimeScaffoldConsistencyTest extends TestCase
{
    public function testDockerComposeBaseDefinesSchedulerServiceUsedByOverlays(): void
    {
        $root = dirname(__DIR__, 3) . '/scaffold';
        $baseCompose = file_get_contents($root . '/docker-compose.yml');
        $mysqlOverlay = file_get_contents($root . '/docker-compose.mysql.yml');
        $redisOverlay = file_get_contents($root . '/docker-compose.redis.yml');
        $natsOverlay = file_get_contents($root . '/docker-compose.nats.yml');

        self::assertIsString($baseCompose);
        self::assertIsString($mysqlOverlay);
        self::assertIsString($redisOverlay);
        self::assertIsString($natsOverlay);

        self::assertStringContainsString("\n  scheduler:\n", $baseCompose);
        // In the scheduler's own block: app, setup and cli build from . too,
        // so the whole file says nothing about it (CodeRabbit on installer#134).
        self::assertSame(1, preg_match('/\n  scheduler:\n((?:(?:    .*)?\n)*)/', $baseCompose, $scheduler), 'the scheduler block is found');
        self::assertMatchesRegularExpression('/^    build: \.$/m', $scheduler[1], 'the scheduler builds the app image');
        self::assertStringContainsString("\n  scheduler:\n", $mysqlOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $redisOverlay);
        self::assertStringContainsString("\n  scheduler:\n", $natsOverlay);
    }

    public function testShellEntryPointKeepsEnvScopeOnCommittedRuntimeFiles(): void
    {
        $root = dirname(__DIR__, 3) . '/scaffold';
        $bin = file_get_contents($root . '/bin/semitexa');

        self::assertIsString($bin);
        self::assertStringContainsString('.env.default', $bin);
        // .env on its own: every .env.default contains ".env" (CodeRabbit on installer#134).
        // Read, not mentioned: the loops that load the env files, as code
        // lines (a comment saying ".env is not read" passed the name check;
        // CodeRabbit on installer#135). .env comes last, so it overrides.
        // In each function that reads the env files, not anywhere in the
        // script (CodeRabbit on installer#135): the merge and the existence check.
        foreach (['env_merged_lines', 'env_runtime_file_exists'] as $function) {
            self::assertSame(1, preg_match('/^' . $function . '\(\) \{\n.*?^\}$/ms', $bin, $body), "{$function} must still exist in bin/semitexa");
            self::assertMatchesRegularExpression('/^\s*for _file in "\$PROJECT_ROOT\/\.env\.default" "\$PROJECT_ROOT\/\.env"; do$/m', $body[0], "{$function} reads the project .env after .env.default");
        }
        self::assertStringNotContainsString('.env.local', $bin);
    }

    public function testShellEntryPointOnlyTreatsSelfPortAsReusableWhenAppIsRunning(): void
    {
        $root = dirname(__DIR__, 3) . '/scaffold';
        $bin = file_get_contents($root . '/bin/semitexa');

        self::assertIsString($bin);
        self::assertStringContainsString(
            'project_is_app_running()',
            $bin,
            'bin/semitexa must keep the project-scoped runtime guard available for self-port checks.',
        );
        self::assertStringContainsString(
            'if project_is_app_running "$_project_root_arg"; then',
            $bin,
            'Self-port reuse must be gated by the app runtime state before registry entries are treated as safe.',
        );
        self::assertStringContainsString(
            'if [ "$_project_running" -eq 1 ] && [ "$PROJECT_ROOT" = "$_project_root_arg" ] && [ "$UPSTREAM_PORT" = "$_port" ]; then',
            $bin,
            'Router registry entries must only bypass a host-port conflict when the matching app is actually running.',
        );
        self::assertStringContainsString(
            'if [ "$_project_running" -eq 1 ] && [ "$SWOOLE_PORT" = "$_port" ]; then',
            $bin,
            'App registry entries must only bypass a host-port conflict when the matching app is actually running.',
        );
    }
}
