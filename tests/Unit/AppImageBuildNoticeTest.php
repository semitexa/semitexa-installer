<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A new project's first server:start compiled the runtime image for minutes
 * while the screen said only "Building and starting the containers"
 * (tk-first-run-image-build-minutes). bin/semitexa now says how long the
 * first build takes, once, before it starts, and only when the image is not
 * there: a later start reuses the image and prints nothing more than before.
 */
final class AppImageBuildNoticeTest extends TestCase
{
    private const NOTICE = '[INFO] No app image yet: the first build compiles the PHP extensions and takes several minutes';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-image-notice-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o777, true);
        foreach (['docker-compose.yml', 'Dockerfile', 'composer.json', '.env.default'] as $file) {
            touch($this->root . '/' . $file);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** Docker as the script sees it: Compose names the image, `image ls` answers with its id or nothing. */
    private static function docker(string $imageLs, string $config = "printf 'demo-app\\n'"): string
    {
        return <<<SH
            run_compose() {
                echo 'compose: a warning of its own' >&2
                case "\$*" in 'config --images app') {$config} ;; *) echo "UNEXPECTED run_compose \$*"; return 1 ;; esac
            }
            docker() {
                case "\$*" in 'image ls -q demo-app') {$imageLs} ;; *) echo "UNEXPECTED docker \$*"; return 1 ;; esac
            }
            info()    { printf '[INFO] %s\\n' "\$*"; }
            success() { printf '[OK] %s\\n' "\$*"; }
            warn()    { printf '[WARN] %s\\n' "\$*"; }
            error()   { printf '[ERROR] %s\\n' "\$*" >&2; }
            SH;
    }

    /** Runs $body after the named functions of bin/semitexa; returns stdout and stderr together. */
    private function sh(string $stubs, array $functions, string $body): string
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/bin/semitexa');
        self::assertSame(1, preg_match('/^APP_IMAGE_BUILD_WAIT=.*$/m', $script, $wait), 'APP_IMAGE_BUILD_WAIT must still exist in bin/semitexa');
        $sh = 'PROJECT_ROOT=' . escapeshellarg($this->root) . "\n" . $stubs . "\n" . ($wait[0] ?? '') . "\n";
        foreach ($functions as $name) {
            self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}$/ms', $script, $m), $name . ' must still exist in bin/semitexa');
            $sh .= ($m[0] ?? '') . "\n";
        }

        return (string) shell_exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg($sh . $body) . ' 2>&1 < /dev/null');
    }

    /** cmd_server_start with everything around the build stubbed; BUILD marks where the image is built. */
    private function serverStart(string $docker): string
    {
        $stubs = $docker . "\n" . <<<'SH'
            local_app_reconcile() { :; }
            stack_event() { :; }
            compose_args() { :; }
            server_start_config_warnings() { :; }
            project_has_shared_domains() { return 1; }
            project_ownership_reclaim() { echo 'BUILD (ownership handover)'; }
            server_start_run_with_port_conflict_fallback() { echo 'BUILD (up -d --build)'; exit 0; }
            SH;

        return $this->sh($stubs, ['app_image_state', 'app_image_build_notice', 'cmd_server_start'], "\ncmd_server_start");
    }

    /** @return list<string> */
    private static function linesFrom(string $output, string $needle): array
    {
        return array_values(array_filter(explode("\n", $output), static fn (string $line): bool => str_contains($line, $needle)));
    }

    #[Test]
    public function a_first_start_says_how_long_the_build_takes_before_it_builds(): void
    {
        $out = $this->serverStart(self::docker(':'));

        self::assertCount(1, self::linesFrom($out, self::NOTICE), $out);
        self::assertMatchesRegularExpression('/about \d+ on a typical machine/', $out);
        $notice = strpos($out, self::NOTICE);
        self::assertNotFalse($notice);
        self::assertLessThan(strpos($out, 'BUILD (ownership handover)'), $notice, 'the handover may run the same build, unseen: the notice comes first');
        self::assertLessThan(strpos($out, 'BUILD (up -d --build)'), $notice);
    }

    #[Test]
    public function a_later_start_reuses_the_image_and_says_nothing(): void
    {
        $out = $this->serverStart(self::docker("printf 'a1b2c3d4e5f6\\n'"));

        self::assertStringContainsString('BUILD (up -d --build)', $out);
        self::assertStringNotContainsString('No app image', $out);
        self::assertStringNotContainsString('minutes', $out);
    }

    #[Test]
    public function a_start_promises_no_wait_when_docker_cannot_tell(): void
    {
        $cases = [
            'the daemon does not answer' => self::docker('return 1'),
            'a Compose without config --images' => self::docker(':', "echo 'unknown flag: --images' >&2; return 1"),
            'Compose names no image' => self::docker(':', ':'),
        ];
        self::assertCount(3, $cases);
        foreach ($cases as $case => $docker) {
            $out = $this->serverStart($docker);
            self::assertStringContainsString('BUILD (up -d --build)', $out, $case);
            self::assertStringNotContainsString('minutes', $out, $case);
        }
    }

    #[Test]
    public function the_lookup_itself_prints_nothing(): void
    {
        foreach (["printf 'a1b2c3d4e5f6\\n'" => '0', ':' => '1', 'return 1' => '2'] as $imageLs => $state) {
            $out = $this->sh(self::docker($imageLs), ['app_image_state'], "\napp_image_state; printf '%s' \"\$?\"");
            self::assertSame($state, $out, 'no image name, id or Compose warning reaches the screen');
        }
    }

    #[Test]
    public function off_a_terminal_the_notice_is_one_line_and_the_build_output_stays_in_the_log(): void
    {
        $stubs = self::docker(':') . "\nthe_build() { echo '#12 [stage 4/9] RUN pecl install swoole'; echo 'noise' >&2; }";
        $out = $this->sh(
            $stubs,
            ['app_image_state', 'app_image_build_notice', 'stdout_is_quiet', 'quiet_step_log', 'run_quiet_step'],
            "\napp_image_build_notice\nrun_quiet_step server-start 'Building and starting the containers' the_build",
        );
        $lines = explode("\n", trim($out));

        self::assertCount(2, $lines, $out);
        self::assertStringStartsWith(self::NOTICE, $lines[0]);
        self::assertStringStartsWith('[OK] Building and starting the containers: done (full output: ', $lines[1]);
        self::assertStringNotContainsString('pecl install', $out);
    }

    #[Test]
    public function install_gives_the_same_wait_and_only_while_the_image_is_missing(): void
    {
        $stubs = "\n" . <<<'SH'
            has_compose() { return 0; }
            composer_quiet_flag() { COMPOSER_QUIET_FLAG=''; }
            run_quiet_step() { echo "STEP $2"; }
            refresh_local_semitexa_packages() { :; }
            SH;
        $functions = ['app_image_state', 'app_image_build_notice', 'cmd_install', 'cmd_demo_install'];

        foreach (['cmd_install', 'cmd_demo_install'] as $command) {
            $first = $this->sh(self::docker(':') . $stubs, $functions, "\n" . $command);
            self::assertCount(1, self::linesFrom($first, self::NOTICE), $first);
            self::assertLessThan(strpos($first, 'STEP Building the app image'), strpos($first, self::NOTICE), $command);

            // Install is most likely the first run: a Docker that cannot tell still gets the line.
            $unknown = $this->sh(self::docker('return 1') . $stubs, $functions, "\n" . $command);
            self::assertCount(1, self::linesFrom($unknown, self::NOTICE), $unknown);

            $again = $this->sh(self::docker("printf 'a1b2c3d4e5f6\\n'") . $stubs, $functions, "\n" . $command);
            self::assertStringContainsString('STEP Building the app image', $again);
            self::assertStringNotContainsString('minutes', $again, $command . ' with the image built');
        }
    }
}
