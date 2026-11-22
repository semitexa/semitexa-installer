<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The E2E phase of test:run in bin/semitexa
 * (tk-e2e-seeding-reads-like-a-framework-update). The E2E database gets its
 * schema and data patches from `semitexa update --no-composer --no-scaffold`,
 * and everything that command printed went into the run: "Semitexa Update
 * Plan", a composer command, every package, "[OK] Update completed. Installed
 * release: ...". An agent read that as test:run having updated the framework.
 * Now the output goes to var/log/e2e-seed.log and the run gets one line that
 * names the step as seeding; a seeding that failed prints all of it and fails.
 */
final class E2eSeedingSaysWhatItIsTest extends TestCase
{
    /** What the update command prints, in short. */
    private const UPDATE_SAID = "Semitexa Update Plan\n"
        . "Composer package update\n"
        . "  Command: composer update \"semitexa/*\" -W\n"
        . "New skeleton packages available\n"
        . " [OK] Update completed. Installed release: semitexa/ultimate 2026.11.18.1431\n";

    private const SEEDING = "INFO E2E database 'semitexa_e2e': seeding its schema and data patches "
        . '(no package and no project file is updated; output in %s)...';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-e2e-seed-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
        file_put_contents($this->tmp . '/said', self::UPDATE_SAID);
        // compose: records the call, prints what the update prints (the last line on stderr), exits as `status` says.
        file_put_contents(
            $this->tmp . '/compose',
            "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($this->tmp . '/calls') . "\n"
            . 'sed \'$d\' ' . escapeshellarg($this->tmp . '/said') . "\n"
            . 'tail -n 1 ' . escapeshellarg($this->tmp . '/said') . " >&2\n"
            . 'exit "$(cat ' . escapeshellarg($this->tmp . '/status') . ")\"\n",
        );
        chmod($this->tmp . '/compose', 0o755);
        file_put_contents($this->tmp . '/status', "0\n");

        file_put_contents($this->tmp . '/seed.sh', 'PROJECT_ROOT=' . escapeshellarg($this->tmp) . "\n"
            . 'info() { printf "INFO %s\n" "$*"; }; error() { printf "ERROR %s\n" "$*" >&2; }' . "\n"
            . 'env_export_compose_context() { :; }; e2e_app_args() { printf "%s" "-e DB_DATABASE=semitexa_e2e"; }' . "\n"
            . self::function('e2e_database_seed') . "\n");
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    #[Test]
    public function a_seeding_that_worked_is_one_line_that_says_what_it_is(): void
    {
        $out = $this->shell('e2e_database_seed ' . escapeshellarg($this->tmp . '/compose') . ' "-f a.yml" semitexa_e2e; echo "status=$?"');

        self::assertSame([sprintf(self::SEEDING, 'var/log/e2e-seed.log'), 'status=0'], $out, 'nothing of the update plan reaches the run');
        // Both streams of the command are kept, for whoever wants to read them.
        $log = (string) file_get_contents($this->tmp . '/var/log/e2e-seed.log');
        foreach (explode("\n", trim(self::UPDATE_SAID)) as $line) {
            self::assertStringContainsString($line, $log);
        }
        self::assertSame(
            "-f a.yml run --rm --no-deps -T -e DB_DATABASE=semitexa_e2e app php vendor/bin/semitexa update --no-composer --no-scaffold\n",
            (string) file_get_contents($this->tmp . '/calls'),
        );
    }

    #[Test]
    public function a_seeding_that_failed_prints_all_it_said_and_fails(): void
    {
        file_put_contents($this->tmp . '/status', "3\n");
        $seed = 'e2e_database_seed ' . escapeshellarg($this->tmp . '/compose') . ' "" semitexa_e2e';
        $out = $this->shell($seed . '; echo "status=$?"');

        self::assertSame(sprintf(self::SEEDING, 'var/log/e2e-seed.log'), $out[0] ?? '');
        self::assertSame("ERROR Seeding the E2E database 'semitexa_e2e' failed (exit 3). What the seeding printed:", $out[1] ?? '');
        $said = array_slice($out, 2, -1);
        sort($said);
        $expected = explode("\n", trim(self::UPDATE_SAID));
        sort($expected);
        self::assertSame($expected, $said, 'every line of it, from both streams');
        self::assertSame('status=1', $out[\count($out) - 1] ?? '');

        // Under set -e, as the script runs: the function returns, the caller decides.
        $out = $this->shell('set -e; ' . $seed . ' 2>/dev/null || echo "the phase fails"; echo "and the shell went on"');
        self::assertSame([sprintf(self::SEEDING, 'var/log/e2e-seed.log'), 'the phase fails', 'and the shell went on'], $out);

        // A run after it that works does not show the failure before.
        file_put_contents($this->tmp . '/status', "0\n");
        file_put_contents($this->tmp . '/said', "nothing to do\nok\n");
        self::assertSame([sprintf(self::SEEDING, 'var/log/e2e-seed.log'), 'status=0'], $this->shell($seed . '; echo "status=$?"'));
        self::assertSame("nothing to do\nok\n", (string) file_get_contents($this->tmp . '/var/log/e2e-seed.log'));
    }

    /** var/log left by a root run, or not a directory at all: the seeding goes on and says where its output is. */
    #[Test]
    public function a_log_that_cannot_be_written_in_the_project_is_kept_elsewhere(): void
    {
        mkdir($this->tmp . '/var');
        file_put_contents($this->tmp . '/var/log', "not a directory\n");
        mkdir($this->tmp . '/tmp');
        $out = $this->shell('TMPDIR=' . escapeshellarg($this->tmp . '/tmp') . '; e2e_database_seed ' . escapeshellarg($this->tmp . '/compose') . ' "" semitexa_e2e; echo "status=$?"');

        $logs = glob($this->tmp . '/tmp/semitexa-e2e-seed.*') ?: [];
        self::assertCount(1, $logs);
        self::assertSame([sprintf(self::SEEDING, $logs[0]), 'status=0'], $out);
        self::assertStringContainsString('Semitexa Update Plan', (string) file_get_contents($logs[0]));

        file_put_contents($this->tmp . '/status', "1\n");
        $out = $this->shell('TMPDIR=' . escapeshellarg($this->tmp . '/tmp') . '; e2e_database_seed ' . escapeshellarg($this->tmp . '/compose') . ' "" semitexa_e2e; echo "status=$?"');
        self::assertContains('Semitexa Update Plan', $out);
        self::assertSame('status=1', $out[\count($out) - 1] ?? '');

        // The directory is there and the file in it cannot be written: the redirection itself fails.
        unlink($this->tmp . '/var/log');
        mkdir($this->tmp . '/var/log/e2e-seed.log', 0o777, true);
        file_put_contents($this->tmp . '/status', "0\n");
        $out = $this->shell('TMPDIR=' . escapeshellarg($this->tmp . '/tmp') . '; e2e_database_seed ' . escapeshellarg($this->tmp . '/compose') . ' "" semitexa_e2e; echo "status=$?"');
        self::assertSame('status=0', $out[\count($out) - 1] ?? '', 'the shell went on past the failed redirection');
        self::assertCount(3, glob($this->tmp . '/tmp/semitexa-e2e-seed.*') ?: []);
    }

    /** The reset seeds through it and stops when it failed; nothing else runs the update into the run. */
    #[Test]
    public function the_reset_seeds_through_it_and_stops_on_a_failure(): void
    {
        $reset = self::function('e2e_database_reset');

        self::assertStringContainsString("    e2e_database_seed \"\$1\" \"\$2\" \"\$_e2e_name\" || return 1\n    e2e_fixtures_apply ", $reset);
        self::assertStringNotContainsString('semitexa update', $reset);
        self::assertSame(1, substr_count(self::script(), 'vendor/bin/semitexa update --no-composer --no-scaffold'), 'one place seeds, and it is the one that keeps the output');
    }

    /** @return list<string> the lines printed */
    private function shell(string $snippet): array
    {
        $sh = '. ' . escapeshellarg($this->tmp . '/seed.sh') . "\n" . $snippet;
        $raw = (string) shell_exec('env -i PATH=/usr/bin:/bin ' . self::sh() . ' -c ' . escapeshellarg($sh) . ' 2>&1');

        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    /** The shell under test: sh, or the one SEMITEXA_TEST_SH names (dash, bash, "busybox sh"). */
    private static function sh(): string
    {
        $sh = getenv('SEMITEXA_TEST_SH');

        return \is_string($sh) && preg_match('/^[a-z]+( sh)?$/', $sh) === 1 ? $sh : 'sh';
    }

    private static function function(string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n.*?^\}$/ms', self::script(), $m), $name . ' must still exist in bin/semitexa');

        return $m[0] ?? '';
    }

    private static function script(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/bin/semitexa');
    }
}
