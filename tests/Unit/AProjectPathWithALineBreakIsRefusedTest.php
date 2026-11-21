<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa finds its project from where it lives
 * (tk-project-root-capture-drops-a-trailing-newline). `$(cd ... && pwd)` strips
 * every trailing newline, so a project at '/work/shop<LF>' was taken for
 * '/work/shop', its sibling, and every command ran on that other project. The
 * path is now read whole, and one with a line break is refused before anything
 * runs, as the app registry already refused one.
 *
 * Each run is the script's own opening, up to where it has entered the project,
 * placed in a project's bin/ and asked where it stands.
 */
final class AProjectPathWithALineBreakIsRefusedTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../scaffold/bin/semitexa';

    /** The first line after the opening this test runs. */
    private const OPENING_ENDS = '# ── Colour helpers';

    private string $tmp = '';

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/semitexa-project-root-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o777, true);
        $this->tmp = (string) realpath($tmp);
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    #[Test]
    public function a_project_whose_name_ends_in_a_line_break_is_refused_rather_than_taken_for_its_sibling(): void
    {
        mkdir($this->tmp . '/shop');

        [$exit, $out, $err] = $this->runIn($this->tmp . "/shop\n");

        self::assertSame(1, $exit, 'it went on in: ' . $out);
        self::assertSame('', $out);
        self::assertStringContainsString("[ERROR] The project's path contains a line break: '" . $this->tmp . "/shop\n'", $err);
    }

    #[Test]
    public function a_line_break_inside_the_name_is_refused_too(): void
    {
        [$exit, $out, $err] = $this->runIn($this->tmp . "/my\nshop");

        self::assertSame(1, $exit, 'it went on in: ' . $out);
        self::assertSame('', $out);
        self::assertStringContainsString("[ERROR] The project's path contains a line break: '" . $this->tmp . "/my\nshop'", $err);
    }

    #[Test]
    public function a_carriage_return_is_refused_as_the_registry_refuses_one(): void
    {
        [$exit, $out, $err] = $this->runIn($this->tmp . "/shop\r");

        self::assertSame(1, $exit, 'it went on in: ' . $out);
        self::assertSame('', $out);
        self::assertStringContainsString("[ERROR] The project's path contains a line break: '" . $this->tmp . "/shop\r'", $err);
    }

    /** The sentinel goes once: a name ending in its letter, or in a space, stays whole. */
    #[Test]
    public function an_ordinary_project_is_found_whole(): void
    {
        foreach (['shop', 'box', 'my shop '] as $name) {
            [$exit, $out, $err] = $this->runIn($this->tmp . '/' . $name);

            self::assertSame(0, $exit, $name . ': ' . $err);
            self::assertSame('ROOT=[' . $this->tmp . '/' . $name . ']', $out, $name);
        }
    }

    /** @return array{int, string, string} exit code, stdout, stderr */
    private function runIn(string $project): array
    {
        $script = (string) file_get_contents(self::SCRIPT);
        $end = strpos($script, self::OPENING_ENDS);
        self::assertIsInt($end, 'bin/semitexa has no "' . self::OPENING_ENDS . '" line to cut its opening at');
        $opening = substr($script, 0, $end);
        self::assertStringContainsString('PROJECT_ROOT=', $opening, 'the opening no longer finds the project');

        mkdir($project . '/bin', 0o777, true);
        file_put_contents($project . '/bin/semitexa', $opening . "printf 'ROOT=[%s]' \"\$PROJECT_ROOT\"\n");

        $process = proc_open(['sh', $project . '/bin/semitexa'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
