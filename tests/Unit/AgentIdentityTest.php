<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/semitexa names an agent that never joined after the nearest agent CLI
 * among its ancestors. The walk stopped above PID 1, so an agent CLI that is a
 * container's main process (codex as PID 1 running bin/semitexa) was never
 * seen (tk-agent-identity-skips-pid-1); under `docker compose run` the same
 * stop made the walk end before it began, because the shell's parent is PID 1.
 */
final class AgentIdentityTest extends TestCase
{
    /** A process tree as ps reports it, keyed by pid; "$PPID" is this shell's parent. */
    private static function tree(string $rows): string
    {
        return <<<SH
            ps() {
                _pid=''; _want=''
                while [ \$# -gt 0 ]; do case "\$1" in -p) _pid="\$2"; shift 2 ;; -o) _want="\$_want \$2"; shift 2 ;; *) shift ;; esac; done
                case "\$_want" in *lstart=*) printf 'Thu Nov 20 18:00:00 2026\\n'; return 0 ;; esac
                case "\$_pid" in
            {$rows}
                    *) return 1 ;;
                esac
            }
            SH;
    }

    private static function identity(string $stubs): string
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/bin/semitexa');
        self::assertSame(1, preg_match('/^agent_identity\(\) \{\n.*?^\}$/ms', $script, $m), 'agent_identity must still exist in bin/semitexa');
        $sh = $stubs . "\n" . ($m[0] ?? '') . "\nagent_identity\nprintf '%s|%s' \"\${SEMITEXA_AGENT_SESSION:-}\" \"\${SEMITEXA_AGENT_IMPLICIT:-}\"";

        // Run under a shell of its own, so its parent is above PID 1 whatever runs the suite:
        // under `compose run` phpunit is PID 1, and "$PPID" would be init itself.
        return (string) shell_exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg('sh -c "$0"; :') . ' ' . escapeshellarg($sh));
    }

    #[Test]
    public function an_agent_that_is_the_containers_main_process_is_seen(): void
    {
        // PID 1 first: under `compose run` this shell's parent is PID 1 itself.
        $named = self::identity(self::tree(<<<'ROWS'
                    1) printf '  0 codex\n' ;;
                    "$PPID") printf '  1 bash\n' ;;
            ROWS));

        self::assertMatchesRegularExpression('/^codex-[0-9a-f]{6}\|codex$/', $named);
    }

    #[Test]
    public function the_walk_ends_at_init_and_a_person_stays_anonymous(): void
    {
        $anonymous = self::identity(self::tree(<<<'ROWS'
                    1) printf '  0 systemd\n' ;;
                    "$PPID") printf '  1 bash\n' ;;
            ROWS));

        self::assertSame('|', $anonymous, 'no agent CLI up to init: no implicit identity');
    }

    #[Test]
    public function an_agent_below_init_is_still_the_nearest_one(): void
    {
        $named = self::identity(self::tree(<<<'ROWS'
                    1) printf '  0 codex\n' ;;
                    "$PPID") printf '  4242 bash\n' ;;
                    4242) printf '  1 aider\n' ;;
            ROWS));

        self::assertMatchesRegularExpression('/^aider-[0-9a-f]{6}\|aider$/', $named, 'the nearest agent names it, not the one at PID 1');
    }
}
