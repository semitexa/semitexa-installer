<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-worker-roles-only-what-the-project-uses. The worker container started a
 * php for each line of worker:processes, and each loaded the framework for
 * itself: about 74 MB a role, 365 MiB at rest for five roles. With a core
 * that has worker:run it runs that one command, which forks the roles.
 *
 * A forked role runs the code its parent loaded, not what is on disk, so the
 * commands that reload the app (server:reload, server:restart app) tell the
 * worker to load the code again too: without that its roles stayed on the old
 * code until a full server:restart, with nothing saying so. Only a container
 * that runs worker:run is told: HUP kills the shell loops with their jobs.
 */
final class WorkerRunsAsOneProcessTest extends TestCase
{
    private const COMPOSE = __DIR__ . '/../../scaffold/docker-compose.worker.yml';
    private const BIN = __DIR__ . '/../../scaffold/bin/semitexa';
    private const WORKER_RUN = 'vendor/semitexa/core/src/Application/Console/Command/WorkerRunCommand.php';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-worker-run-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/bin', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** exec: worker:run takes the shell's place, so docker stop's TERM and a HUP reach it with nothing between. */
    #[Test]
    public function with_a_core_that_has_worker_run_the_container_runs_that_one_command(): void
    {
        $this->coreHasWorkerRun();

        [$started, $alive] = $this->runWorkerScript("printf '5 queue:work nats events\\n'");

        self::assertSame(['-d memory_limit=512M vendor/bin/semitexa worker:run'], $started, 'one php, with the memory limit each role had; worker:processes is not asked by the shell');
        self::assertSame(1, $alive, 'no shell is left around it');
    }

    /** A core from before worker:run is told by the file, and keeps a php for each line. */
    #[Test]
    public function without_worker_run_in_the_core_each_line_still_gets_its_own_php(): void
    {
        [$started] = $this->runWorkerScript("printf '5 queue:work nats events\\n5 mail:work nats mail\\n'", 3);

        sort($started);
        self::assertSame([
            '-d memory_limit=512M vendor/bin/semitexa mail:work nats mail',
            '-d memory_limit=512M vendor/bin/semitexa queue:work nats events',
            'vendor/bin/semitexa worker:processes',
        ], $started);
    }

    /** Every replica that runs worker:run is sent HUP, by its own id. */
    #[Test]
    public function a_worker_container_that_runs_worker_run_is_sent_hup(): void
    {
        touch($this->tmp . '/docker-compose.worker.yml');

        self::assertSame(
            "compose: ps -q worker\nprobe: runs-worker-run-1\ndocker: kill -s HUP runs-worker-run-1\nprobe: runs-worker-run-2\ndocker: kill -s HUP runs-worker-run-2\n[INFO] Worker: every role finishes the work in hand, then loads the code again.\nrc=0\n",
            $this->reload(['runs-worker-run-1', 'runs-worker-run-2']),
        );
    }

    /**
     * REGRESSION (review). The first version asked whether the core has
     * worker:run and sent HUP to whatever the worker service was. A container
     * started before the core was updated, or from a compose file the project
     * kept, is still the shell with a php for each role: HUP killed that shell
     * and the container with its jobs half done. The container is asked what
     * it runs, and one that does not run worker:run is left alone.
     */
    #[Test]
    public function a_worker_container_still_running_the_shell_loops_is_left_alone_though_the_core_has_worker_run(): void
    {
        $this->coreHasWorkerRun();
        touch($this->tmp . '/docker-compose.worker.yml');

        self::assertSame("compose: ps -q worker\nprobe: runs-shell-loops\nrc=0\n", $this->reload(['runs-shell-loops']), 'no kill, and nothing claimed');
        self::assertSame(
            "compose: ps -q worker\nprobe: runs-shell-loops\nprobe: runs-worker-run-2\ndocker: kill -s HUP runs-worker-run-2\n[INFO] Worker: every role finishes the work in hand, then loads the code again.\nrc=0\n",
            $this->reload(['runs-shell-loops', 'runs-worker-run-2']),
            'each replica is asked for itself',
        );
    }

    #[Test]
    public function a_project_without_the_worker_file_asks_nothing(): void
    {
        self::assertSame("rc=0\n", $this->reload(['runs-worker-run-1']));
    }

    /** No worker container running is not the reload's failure, and nothing is claimed. */
    #[Test]
    public function a_worker_that_is_not_running_fails_nothing_and_says_nothing(): void
    {
        touch($this->tmp . '/docker-compose.worker.yml');

        self::assertSame("compose: ps -q worker\nrc=0\n", $this->reload([]));
    }

    /**
     * What the container is asked, run here on process tables as /proc gives
     * them. The shell loops' script names `vendor/bin/semitexa worker:run`
     * in its own text (the line that would exec it), so finding those words
     * in a command line is not enough: the main process must be the php.
     */
    #[Test]
    public function the_probe_tells_worker_run_from_a_shell_whose_script_only_names_it(): void
    {
        $init = "/sbin/docker-init\0--\0docker-php-entrypoint\0sh\0-c\0exec php vendor/bin/semitexa worker:run\n\0";
        $script = "sh\0-c\0if [ -f x ]; then\n  exec php -d memory_limit=512M vendor/bin/semitexa worker:run\nfi\nrun() { php vendor/bin/semitexa \"\$@\"; }\n\0";

        self::assertSame(0, $this->probe([
            1 => [0, $init],
            7 => [1, "php\0-d\0memory_limit=512M\0vendor/bin/semitexa\0worker:run\0"],
            11 => [7, "semitexa queue:work nats events\0"],
        ]), 'worker:run under the container init');
        self::assertSame(0, $this->probe([
            1 => [0, $init],
            7 => [1, "/usr/local/bin/php\0-d\0memory_limit=512M\0vendor/bin/semitexa\0worker:run\0"],
        ]), 'after a HUP it runs under the full path of php');
        self::assertSame(0, $this->probe([
            1 => [0, "php\0vendor/bin/semitexa\0worker:run\0"],
        ]), 'a compose file without init: it is pid 1 itself');

        self::assertSame(1, $this->probe([
            1 => [0, $init],
            6 => [1, $script],
            10 => [6, $script],
            12 => [10, "php\0-d\0memory_limit=512M\0vendor/bin/semitexa\0queue:work\0nats\0events\0"],
        ]), 'the shell loops: the script names worker:run, and no process is it');
        self::assertSame(1, $this->probe([
            1 => [0, $init],
            6 => [1, $script],
            40 => [33, "php\0vendor/bin/semitexa\0worker:run\0"],
        ]), 'a worker:run someone started by hand in the container is not what docker stop and HUP reach');
        self::assertSame(1, $this->probe([
            1 => [0, $init],
            7 => [1, "php\0vendor/bin/semitexa\0worker:processes\0"],
        ]));
    }

    /** The container is asked with that probe, on its own /proc. */
    #[Test]
    public function the_container_is_asked_with_the_probe_on_its_own_proc(): void
    {
        $script = (string) file_get_contents(self::BIN);

        self::assertSame(1, preg_match('/^worker_container_runs_worker_run\(\) \{\n(.*?)^\}$/ms', $script, $m));
        self::assertSame('    docker exec "$1" sh -c "$WORKER_RUN_PROBE" sh /proc >/dev/null 2>&1' . "\n", $m[1] ?? '');
    }

    /** `server:restart app` is what AGENTS.md tells an agent to run after a change: the worker follows it. */
    #[Test]
    public function recreating_the_app_alone_reloads_the_worker_and_recreating_another_service_does_not(): void
    {
        $script = (string) file_get_contents(self::BIN);
        self::assertSame(1, preg_match('/^cmd_server_restart\(\) \{\n.*?^\}$/ms', $script, $m));
        $body = $m[0] ?? '';

        self::assertSame(1, preg_match(
            '/run_compose up -d --no-build --force-recreate --no-deps "\$_service"\n(?:\s*#.*\n)*\s*if \[ "\$_service" = "app" \]; then\n\s*worker_reload_code\n\s*fi\n/',
            $body,
        ), 'after the app is recreated, and only for the app');
        self::assertSame(1, substr_count($body, 'worker_reload_code'), 'a full restart recreates the worker: no HUP on top of it');
    }

    /** server:reload reloads the worker's code once the app's own reload succeeded, and keeps its exit code. */
    #[Test]
    public function server_reload_reloads_the_worker_after_a_reload_that_succeeded(): void
    {
        $script = (string) file_get_contents(self::BIN);

        self::assertStringContainsString(
            '    server:reload)   cmd_proxy "$COMMAND" "$@"; _rc=$?; [ "$_rc" -ne 0 ] || worker_reload_code; exit "$_rc" ;;' . "\n",
            $script,
        );
        foreach ([0 => "proxied: server:reload\nreloaded worker\nrc=0\n", 3 => "proxied: server:reload\nrc=3\n"] as $exit => $expected) {
            $sh = 'cmd_proxy() { echo "proxied: $*"; return ' . $exit . '; }' . "\n"
                . 'worker_reload_code() { echo "reloaded worker"; }' . "\n"
                . 'COMMAND=server:reload; ( case "$COMMAND" in' . "\n"
                . '    server:reload)   cmd_proxy "$COMMAND" "$@"; _rc=$?; [ "$_rc" -ne 0 ] || worker_reload_code; exit "$_rc" ;;' . "\n"
                . 'esac ); echo "rc=$?"';
            self::assertSame($expected, (string) shell_exec('sh -c ' . escapeshellarg($sh) . ' 2>&1'), "app reload exit {$exit}");
        }
    }

    private function coreHasWorkerRun(): void
    {
        mkdir(dirname($this->tmp . '/' . self::WORKER_RUN), 0o777, true);
        touch($this->tmp . '/' . self::WORKER_RUN);
    }

    /**
     * worker_reload_code in the throwaway project. `compose ps -q worker`
     * gives $containers; a container whose name starts with runs-worker-run
     * answers the probe with yes.
     *
     * @param list<string> $containers
     */
    private function reload(array $containers): string
    {
        $script = (string) file_get_contents(self::BIN);
        self::assertSame(1, preg_match('/^worker_reload_code\(\) \{\n.*?^\}$/ms', $script, $m), 'worker_reload_code must exist in bin/semitexa');
        $sh = 'run_compose() { echo "compose: $*" >&3; [ "$*" != "ps -q worker" ] || printf "%s\n" ' . implode(' ', array_map('escapeshellarg', $containers)) . "; }\n"
            . 'worker_container_runs_worker_run() { echo "probe: $1" >&3; case "$1" in runs-worker-run*) return 0 ;; esac; return 1; }' . "\n"
            . 'docker() { echo "docker: $*" >&3; }' . "\n"
            . 'info() { echo "[INFO] $*"; }' . "\n"
            . ($m[0] ?? '') . "\n" . 'worker_reload_code; echo "rc=$?"';

        return (string) shell_exec(sprintf(
            'env -i PATH=/usr/bin:/bin PROJECT_ROOT=%s sh -c %s 3>&1 2>&1',
            escapeshellarg($this->tmp),
            escapeshellarg($sh),
        ));
    }

    /**
     * WORKER_RUN_PROBE on a process table written as /proc holds one.
     *
     * @param array<int, array{int, string}> $processes pid => [parent pid, cmdline as /proc gives it]
     * @return int the probe's exit code: 0 when the container runs worker:run
     */
    private function probe(array $processes): int
    {
        $script = (string) file_get_contents(self::BIN);
        self::assertSame(1, preg_match("/^WORKER_RUN_PROBE='\n(.*?)^'$/ms", $script, $m), 'WORKER_RUN_PROBE must exist in bin/semitexa');
        $proc = $this->tmp . '/proc-' . bin2hex(random_bytes(4));
        mkdir($proc . '/self', 0o777, true);
        foreach ($processes as $pid => [$parent, $cmdline]) {
            mkdir("{$proc}/{$pid}");
            file_put_contents("{$proc}/{$pid}/cmdline", $cmdline);
            file_put_contents("{$proc}/{$pid}/status", "Name:\tx\nPid:\t{$pid}\nPPid:\t{$parent}\n");
        }
        exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg($m[1] ?? 'exit 9') . ' sh ' . escapeshellarg($proc) . ' 2>&1', $output, $code);
        self::assertSame([], $output, 'the probe prints nothing');

        return $code;
    }

    /**
     * The worker service's own script with a stand-in php that records its
     * arguments and stays up; worker:processes prints what $listing says.
     *
     * @return array{list<string>, int} what php was started with, and the processes alive in the script's group
     */
    private function runWorkerScript(string $listing, int $expected = 1): array
    {
        $yaml = (string) file_get_contents(self::COMPOSE);
        self::assertSame(1, preg_match('/^    command:\n      - sh\n      - -c\n      - \|\n((?:        .*\n|\n)+)/m', $yaml, $m), 'the worker command is an inline shell script');
        $body = str_replace(['$$', '${PHP_MEMORY_LIMIT:-512M}'], ['$', '512M'], (string) preg_replace('/^        /m', '', $m[1]));

        file_put_contents($this->tmp . '/bin/php', "#!/bin/sh\necho \"\$*\" >> \"\$WORK_LOG\"\nfor a in \"\$@\"; do case \$a in worker:processes) {$listing}; exit 0 ;; esac; done\nexec sleep 30\n");
        chmod($this->tmp . '/bin/php', 0o755);
        $log = $this->tmp . '/work.log';
        $pid = (int) shell_exec(sprintf(
            'cd %s && PATH=%s:$PATH WORK_LOG=%s setsid sh -c %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($this->tmp),
            escapeshellarg($this->tmp . '/bin'),
            escapeshellarg($log),
            escapeshellarg($body),
        ));
        $read = static fn (): array => is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
        for ($i = 0; $i < 50 && count($read()) < $expected; $i++) {
            usleep(100_000);
        }
        usleep(300_000);
        $alive = 0;
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            $line = (string) @file_get_contents($stat);
            $fields = explode(' ', substr($line, (int) strrpos($line, ')') + 2));
            if (($fields[2] ?? '') === (string) $pid && $fields[0] !== 'Z') {
                $alive++;
            }
        }
        exec('kill -KILL -' . $pid . ' 2>/dev/null');

        return [$read(), $alive];
    }
}
