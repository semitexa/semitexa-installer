<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Swoole server runs under the CLI SAPI, where OPcache is off unless asked for
 * (tk-opcache-for-the-swoole-server). Measured 2026-11-21 on the framework's own dev
 * stack: with it off every worker compiled the framework into its own private heap.
 * The app service's php flags turn it on for the server alone, sized for the whole
 * framework, and leave timestamp validation on, checked at every include, so a
 * restart and a reload both pick up an edited file.
 */
final class ServerRunsWithOpcacheTest extends TestCase
{
    private const COMPOSE = __DIR__ . '/../../scaffold/docker-compose.yml';

    #[Test]
    public function the_server_command_turns_opcache_on_by_default(): void
    {
        $ini = self::serverIni();

        self::assertSame('${PHP_OPCACHE:-1}', $ini['opcache.enable_cli'] ?? null, 'without it a CLI process has no OPcache and each worker compiles its own copy');
        self::assertSame('server.php', self::serverCommand()[count(self::serverCommand()) - 1]);
    }

    #[Test]
    public function the_cache_is_sized_above_the_php_defaults(): void
    {
        $ini = self::resolved(self::serverIni());

        self::assertGreaterThanOrEqual(256, (int) ($ini['opcache.memory_consumption'] ?? 0), 'the default 128 MB is too close to what the framework compiles to');
        self::assertGreaterThanOrEqual(32, (int) ($ini['opcache.interned_strings_buffer'] ?? 0), 'the default 8 MB fills up and the rest lands in each worker');
        self::assertGreaterThanOrEqual(30000, (int) ($ini['opcache.max_accelerated_files'] ?? 0), 'a file past the limit is compiled by every worker');
    }

    #[Test]
    public function an_edited_file_is_still_picked_up(): void
    {
        $ini = self::serverIni();

        self::assertArrayNotHasKey('opcache.validate_timestamps', $ini, 'the PHP default validates timestamps: turned off, a restart would be the only way to see an edit, and a reload none');
        self::assertSame('0', $ini['opcache.revalidate_freq'] ?? null, 'a CLI process is one request that never ends: at the default 2 s OPcache never checks a file again, and server:reload served the old code (measured 2026-11-21)');
        self::assertSame('0', $ini['opcache.file_update_protection'] ?? null, 'for a CLI process the request began when the master did: at the default 2 s a file an update changed is compiled but never cached, so each reloaded worker holds its own copy until the master restarts (review ultimate#179)');
        self::assertArrayNotHasKey('opcache.file_cache', $ini);
        self::assertArrayNotHasKey('opcache.preload', $ini);
    }

    /** The flags as php itself reads them: the server's own php, with them, has OPcache on. */
    #[Test]
    public function php_started_with_these_flags_has_opcache_enabled(): void
    {
        self::assertTrue(extension_loaded('Zend OPcache'), 'the image the server runs in ships OPcache; the tests run in that image');

        $flags = '';
        foreach (self::resolved(self::serverIni()) as $name => $value) {
            if (str_starts_with($name, 'opcache.')) {
                $flags .= ' -d ' . escapeshellarg($name . '=' . $value);
            }
        }
        $probe = 'echo json_encode([(opcache_get_status(false) ?: [])["opcache_enabled"] ?? false, ini_get("opcache.validate_timestamps"), ini_get("opcache.revalidate_freq")]);';

        $with = shell_exec(escapeshellarg(PHP_BINARY) . $flags . ' -r ' . escapeshellarg($probe));
        $without = shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -r ' . escapeshellarg($probe));

        self::assertSame('[true,"1","0"]', $with);
        self::assertStringStartsWith('[false,', (string) $without, 'the probe tells on from off');
    }

    /**
     * The app service's command, one list item each, comments left out.
     *
     * @return list<string>
     */
    private static function serverCommand(): array
    {
        $yaml = (string) file_get_contents(self::COMPOSE);
        self::assertSame(1, preg_match('/^  app:\n(?:(?:    .*)?\n)*?    command:\n((?:      .*\n)+)/m', $yaml, $m), 'the app service has a block-list command');

        $items = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^      - (.+)$/', $line, $item) === 1) {
                $items[] = trim($item[1]);
            }
        }

        return $items;
    }

    /**
     * Every `-d name=value` of the server command.
     *
     * @return array<string, string>
     */
    private static function serverIni(): array
    {
        $command = self::serverCommand();
        $ini = [];
        foreach ($command as $i => $word) {
            if ($word === '-d' && isset($command[$i + 1]) && str_contains($command[$i + 1], '=')) {
                [$name, $value] = explode('=', $command[$i + 1], 2);
                $ini[$name] = $value;
            }
        }

        return $ini;
    }

    /**
     * `${NAME:-default}` as compose resolves it with NAME unset.
     *
     * @param array<string, string> $ini
     * @return array<string, string>
     */
    private static function resolved(array $ini): array
    {
        return array_map(static fn (string $value): string => (string) preg_replace('/\$\{[A-Z_]+:-([^}]*)\}/', '$1', $value), $ini);
    }
}
