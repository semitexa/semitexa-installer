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

    private const MODEL = "printf 'name: demo\\nservices:\\n  app:\\n    build:\\n      context: /srv/demo\\n  setup:\\n    build:\\n      context: /srv/demo\\nnetworks:\\n  app:\\n    image: not-a-service\\n'";

    /**
     * Docker as the script sees it. Compose lists the images of app and of what it depends on, the
     * dependency first; `image ls` answers $imageLs for the app image and $others for every other
     * name: by default setup's image exists, and so does an app image under some other tag.
     */
    private static function docker(
        string $imageLs,
        string $listed = "printf 'demo-setup\\ndemo-app\\n'",
        string $model = self::MODEL,
        string $app = 'demo-app:latest',
        string $others = "printf 'ffff00000000\\n'",
    ): string {
        return <<<SH
            run_compose() {
                echo 'compose: a warning of its own' >&2
                case "\$*" in
                    'config --images app') {$listed} ;;
                    'config app') {$model} ;;
                    *) echo "UNEXPECTED run_compose \$*"; return 1 ;;
                esac
            }
            docker() {
                case "\$*" in
                    'image ls -q {$app}') {$imageLs} ;;
                    'image ls -q '*) {$others} ;;
                    *) echo "UNEXPECTED docker \$*"; return 1 ;;
                esac
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
            'Compose names its images another way' => self::docker(':', "printf 'demo_setup\\ndemo_app\\n'"),
            'the model cannot be read' => self::docker(':', model: 'return 1'),
        ];
        self::assertCount(5, $cases);
        foreach ($cases as $case => $docker) {
            $out = $this->serverStart($docker);
            self::assertStringContainsString('BUILD (up -d --build)', $out, $case);
            self::assertStringNotContainsString('minutes', $out, $case);
        }
    }

    /** The state app_image_state returns: 0 built, 1 to build, 2 cannot tell. */
    private function state(string $docker): string
    {
        return $this->sh($docker, ['app_image_state'], "\napp_image_state; printf '%s' \"\$?\"");
    }

    #[Test]
    public function a_dependencys_image_does_not_stand_for_the_apps(): void
    {
        // review, installer#124: `config --images app` lists setup's image too, in no fixed order.
        $present = "printf 'a1b2c3d4e5f6\\n'";
        foreach (["printf 'demo-setup\\ndemo-app\\n'", "printf 'demo-app\\ndemo-setup\\n'"] as $listed) {
            self::assertSame('1', $this->state(self::docker(':', $listed)), 'setup built, app not: ' . $listed);
            self::assertSame('0', $this->state(self::docker($present, $listed, others: ':')), 'app built, setup not: ' . $listed);
        }
        self::assertCount(1, self::linesFrom($this->serverStart(self::docker(':')), self::NOTICE));
    }

    #[Test]
    public function an_app_image_under_another_tag_is_not_the_one_compose_builds(): void
    {
        // review, installer#124: `image ls demo-app` lists every tag, and Compose builds demo-app:latest.
        self::assertSame('1', $this->state(self::docker(':')), 'only demo-app:old exists');
        self::assertCount(1, self::linesFrom($this->serverStart(self::docker(':')), self::NOTICE));
    }

    #[Test]
    public function an_image_named_in_the_compose_file_is_looked_up_as_named(): void
    {
        $present = "printf 'a1b2c3d4e5f6\\n'";
        $model = static fn (string $image): string => "printf 'name: demo\\nservices:\\n  setup:\\n    image: demo-setup\\n  app:\\n    image: {$image}\\n'";
        $cases = [
            'registry.example/team/app:1.2' => 'registry.example/team/app:1.2',
            'localhost:5000/team/app' => 'localhost:5000/team/app:latest',
            'team/app@sha256:0123abcd' => 'team/app@sha256:0123abcd',
            '"quoted/app"' => 'quoted/app:latest',
        ];
        self::assertCount(4, $cases);
        foreach ($cases as $image => $lookedUp) {
            $listed = "printf 'demo-setup\\n" . trim($image, '"') . "\\n'";
            self::assertSame('0', $this->state(self::docker($present, $listed, $model($image), $lookedUp, ':')), $image . ' built');
            self::assertSame('1', $this->state(self::docker(':', $listed, $model($image), $lookedUp)), $image . ' not built');
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
