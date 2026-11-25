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
        self::assertStringContainsString('build: .', $baseCompose);
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
        self::assertStringContainsString('.env', $bin);
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
