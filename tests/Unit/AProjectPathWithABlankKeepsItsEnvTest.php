<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa reads .env.default and .env through env_merged_lines
 * (tk-domain-discovery-splits-env-filenames). The two paths were joined into one
 * variable and handed to awk unquoted, so the shell split them at every blank: a
 * project at '/work/my shop' asked awk for '/work/my', read no file, and the
 * router found none of its domains. Each path is now one argument.
 *
 * Each run is the script's own functions, cut out by name, with PROJECT_ROOT
 * set to a project made for the test.
 */
final class AProjectPathWithABlankKeepsItsEnvTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scaffold/bin/semitexa';

    private string $tmp = '';

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/semitexa-env-blank-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o777, true);
        $this->tmp = (string) realpath($tmp);
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    #[Test]
    public function a_project_under_a_path_with_a_blank_keeps_its_domains(): void
    {
        $root = $this->project('my shop', "APP_DOMAIN=shop.test\n", "TENANT_DOMAINS=a.shop.test, b.shop.test\n");

        [$exit, $out, $err] = $this->sh($root, 'router_collect_project_domains');

        self::assertSame('', $err);
        self::assertSame(0, $exit);
        self::assertSame("a.shop.test\nb.shop.test\nshop.test\n", $out);
    }

    /** Two blanks in a row and a pattern character: neither is split, squeezed or expanded. */
    #[Test]
    public function the_path_reaches_awk_as_it_is_written(): void
    {
        mkdir($this->tmp . '/axb  c');
        file_put_contents($this->tmp . '/axb  c/.env', "APP_DOMAIN=other.test\n");
        $root = $this->project('a*b  c', "APP_DOMAIN=shop.test\nSWOOLE_PORT=8080\n", "SWOOLE_PORT=9502\n");

        [$exit, $out, $err] = $this->sh($root, 'env_merged_lines');

        self::assertSame('', $err);
        self::assertSame(0, $exit);
        self::assertSame("APP_DOMAIN=shop.test\nSWOOLE_PORT=9502\n", $out);
    }

    #[Test]
    public function a_value_is_read_from_such_a_project_and_env_wins_over_the_default(): void
    {
        $root = $this->project('my shop', "SWOOLE_PORT=8080\nAPP_ENV=dev\n", "SWOOLE_PORT=9502\n");

        [, $out, $err] = $this->sh($root, 'printf "[%s][%s][%s]" "$(env_val SWOOLE_PORT 1)" "$(env_val APP_ENV none)" "$(env_val MISSING fallback)"');

        self::assertSame('', $err);
        self::assertSame('[9502][dev][fallback]', $out);
    }

    /** With one file, or none, only the files that exist are read, and nothing is an error. */
    #[Test]
    public function a_missing_file_is_left_out(): void
    {
        $onlyDefault = $this->project('only default', "APP_DOMAIN=shop.test\n", null);
        $neither = $this->project('no env', null, null);

        self::assertSame([0, "APP_DOMAIN=shop.test\n", ''], $this->sh($onlyDefault, 'env_merged_lines'));
        self::assertSame([0, '', ''], $this->sh($neither, 'env_merged_lines'));
    }

    /** The files are gathered in the function's own arguments: the caller's stay as they were. */
    #[Test]
    public function the_callers_arguments_are_untouched(): void
    {
        $root = $this->project('my shop', "A=1\n", "B=2\n");

        [, $out, $err] = $this->sh($root, 'caller(){ env_merged_lines >/dev/null; printf "%s:%s" "$#" "$1"; }; caller "one two"');

        self::assertSame('', $err);
        self::assertSame('1:one two', $out);
    }

    private function project(string $name, ?string $default, ?string $env): string
    {
        $root = $this->tmp . '/' . $name;
        mkdir($root);
        if ($default !== null) {
            file_put_contents($root . '/.env.default', $default);
        }
        if ($env !== null) {
            file_put_contents($root . '/.env', $env);
        }

        return $root;
    }

    /** @return array{int, string, string} exit code, stdout, stderr */
    private function sh(string $root, string $command): array
    {
        $script = (string) file_get_contents(self::SCRIPT);
        // The first is the helper the old env_merged_lines called: gone now, taken when present.
        $names = ['env_existing_files_low_to_high', 'env_merged_lines', 'env_val', 'router_validate_domain', 'router_collect_project_domains'];
        preg_match_all('/^(' . implode('|', $names) . ')\(\) \{\n.*?^\}$/ms', $script, $m);
        foreach (array_slice($names, 1) as $needed) {
            self::assertContains($needed, $m[1], 'bin/semitexa no longer has ' . $needed . '()');
        }

        $sh = 'PROJECT_ROOT=' . escapeshellarg($root) . "\n" . implode("\n", $m[0]) . "\n" . $command . "\n";
        $process = proc_open(['sh', '-c', $sh], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
