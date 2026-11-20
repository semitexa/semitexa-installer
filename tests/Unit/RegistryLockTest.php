<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The local app registry's lock in bin/semitexa (tk-registry-lock-is-best-effort).
 * It gave up after about 5 s and the registration went on without it, and a
 * release freed it whoever held it. Two interleavings were open: a pruning
 * that held it for longer than 5 s had a registration replace an entry it had
 * read as gone, and two runs taking over one stale lock had one remove the
 * other's new lock. Now the lock names its owner (pid and when that process
 * started), only a dead owner's lock is taken over, only the owner releases,
 * and a registration that cannot take it refuses.
 *
 * Every run here is a shell of its own (sh file &), never a subshell: "$$" is
 * what names the owner, and a subshell shares it with its parent.
 */
final class RegistryLockTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-registry-lock-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/locks', 0o777, true);

        // What every run sources: the two functions as bin/semitexa has them.
        file_put_contents($this->tmp . '/lock.sh', 'T=' . escapeshellarg($this->tmp) . "\n"
            . 'ROUTER_LOCK_DIR="$T/locks"; LOCK="$ROUTER_LOCK_DIR/registry.lock"' . "\n"
            . 'router_ensure_app_dirs() { mkdir -p "$ROUTER_LOCK_DIR"; }' . "\n"
            . 'await() { _aw=0; until [ -e "$T/$1" ]; do _aw=$((_aw + 1)); [ "$_aw" -lt 400 ] || { echo "no $1 after 20 s"; exit 1; }; sleep 0.05; done; }' . "\n"
            . self::function('router_lock_acquire') . "\n" . self::function('router_lock_release') . "\n");

        // A run that takes the lock and holds it for as long as it lives.
        $this->actor('holder', 'router_lock_acquire 1 && touch "$T/ready"; exec sleep 60');
        // A run that takes the lock and gives it back when told to.
        $this->actor('polite', 'router_lock_acquire 1 && touch "$T/ready"; await go; router_lock_release; touch "$T/released"');
        // A run that took the lock and ended without releasing it: its owner is dead.
        $this->actor('killed', 'router_lock_acquire 1');
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** The first interleaving: the lock is held for longer than the wait, and the registration refuses rather than write. */
    #[Test]
    public function a_registration_refuses_while_another_run_holds_the_lock(): void
    {
        $out = $this->shell(
            'app_validate_app_id() { return 0; }; router_app_entry_exists() { return 1; }' . "\n"
            . 'allocate_free_port() { printf 9501; }; port_range_start() { printf 9501; }; port_range_end() { printf 9599; }' . "\n"
            . 'router_app_write_entry() { echo "wrote an entry"; }; router_apply_app_port_to_domains() { :; }' . "\n"
            . 'warn() { printf "WARN %s\n" "$*"; }; error() { printf "ERROR %s\n" "$*"; }' . "\n"
            . self::function('local_app_register_logic') . "\n"
            . 'sh "$T/holder.sh" >/dev/null 2>&1 & holder=$!; await ready' . "\n"
            . 'SEMITEXA_REGISTRY_LOCK_WAIT=1' . "\n"
            // As local-app:register runs it: under set -e.
            . '( set -e; local_app_register_logic app-123456 "" /home/dev/app; echo "went on" ) | sed "s/process $holder)/process HOLDER)/"; ' . "\n"
            . '[ -d "$LOCK" ] && echo "still held"; kill "$holder"',
        );

        self::assertSame([
            'ERROR The local app registry is locked by another bin/semitexa run (process HOLDER) and stayed locked for 1 s, so nothing was registered. '
            . 'Run this again when that run has ended; the lock is ' . $this->tmp . '/locks/registry.lock.',
            'still held',
        ], $out, 'no entry written, no port printed, and the run stopped there');
    }

    #[Test]
    public function a_live_owner_is_never_taken_over_and_releases_its_own_lock(): void
    {
        $out = $this->shell(
            'sh "$T/polite.sh" >/dev/null 2>&1 & polite=$!; await ready' . "\n"
            . 'SEMITEXA_REGISTRY_LOCK_WAIT=1' . "\n"
            . 'router_lock_acquire && echo "taken" || echo "refused"; echo "held=[$ROUTER_LOCK_HELD]"' . "\n"
            . '[ -d "$LOCK" ] && echo "the owner still has it"' . "\n"
            . 'touch "$T/go"; await released; wait "$polite"; [ -d "$LOCK" ] && echo held || echo free',
        );

        self::assertSame(['refused', 'held=[]', 'the owner still has it', 'free'], $out);
    }

    #[Test]
    public function a_release_by_a_run_that_is_not_the_owner_does_nothing(): void
    {
        $out = $this->shell(
            'sh "$T/holder.sh" >/dev/null 2>&1 & holder=$!; await ready' . "\n"
            // A run that never asked for the lock, then one that asked and did not get it.
            . 'router_lock_release; [ -d "$LOCK" ] && echo "kept"' . "\n"
            . 'router_lock_acquire 1 || echo "not taken"; router_lock_release; [ -d "$LOCK" ] && echo "kept"' . "\n"
            . 'kill "$holder"',
        );

        self::assertSame(['kept', 'not taken', 'kept'], $out);
    }

    #[Test]
    public function a_dead_owners_lock_is_taken_over_at_once_and_released(): void
    {
        $out = $this->shell(
            'sh "$T/killed.sh"; [ -d "$LOCK" ] && echo "left behind"' . "\n"
            // One try: no waiting for it.
            . 'router_lock_acquire 1 && echo "taken"; echo "held=[$ROUTER_LOCK_HELD]"' . "\n"
            . 'router_lock_release; [ -d "$LOCK" ] && echo held || echo free',
        );

        self::assertSame(['left behind', 'taken', 'held=[1]', 'free'], $out);
    }

    /** A pid alone does not name a run: the system gives it to another process later. */
    #[Test]
    public function an_owner_whose_pid_now_belongs_to_another_process_is_dead(): void
    {
        $out = $this->shell(
            'sleep 60 >/dev/null 2>&1 & other=$!' . "\n"
            . 'mkdir "$LOCK"; printf "%s|%s|.\n" "$other" "t1" > "$LOCK/owner"' . "\n"
            . 'router_lock_acquire 1 || echo "refused"; echo "held=[$ROUTER_LOCK_HELD]"; kill "$other"',
        );

        self::assertSame(['held=[1]'], $out);
    }

    /** The second interleaving, at its start: of the runs that find one dead lock at the same moment, one takes it. */
    #[Test]
    public function a_dead_owners_lock_is_taken_over_exactly_once_when_runs_race(): void
    {
        // Each tries once at the same signal; the one that takes it stays alive until all have tried.
        $this->actor('racer', 'until [ -e "$T/start" ]; do :; done' . "\n"
            . 'if router_lock_acquire 1; then echo "$$" >> "$T/winners"; touch "$T/tried.$1"; await finish; else touch "$T/tried.$1"; fi');
        $out = $this->shell(
            'sh "$T/killed.sh"' . "\n"
            . 'for n in 1 2 3 4 5 6 7 8; do sh "$T/racer.sh" "$n" >/dev/null 2>&1 & done' . "\n"
            . 'sleep 0.2; touch "$T/start"; for n in 1 2 3 4 5 6 7 8; do await "tried.$n"; done' . "\n"
            . 'echo "winners=$(wc -l < "$T/winners" | tr -d " ")"; touch "$T/finish"; wait',
        );

        self::assertSame(['winners=1'], $out);
    }

    /** The second interleaving, whole: runs that wait on a dead lock each get it in turn, and no two at once. */
    #[Test]
    public function runs_that_find_a_dead_lock_work_one_at_a_time(): void
    {
        $this->workers();
        $out = $this->shell(
            'sh "$T/killed.sh"' . "\n"
            . 'for n in 1 2 3 4 5 6; do sh "$T/worker.sh" >/dev/null 2>&1 & done' . "\n"
            . 'sleep 0.2; touch "$T/start"; wait; cat "$T/log"; [ -d "$LOCK" ] && echo held || echo free',
        );

        self::assertSame(['worked', 'worked', 'worked', 'worked', 'worked', 'worked', 'free'], $out);
    }

    /**
     * The same from a lock with no owner file, where every waiting run reaches
     * the grace at about the same moment. A run that had read "no owner" removed
     * the owner file another had just written, and both held the lock (review
     * of installer 30fcefd: two holders in 34 runs of 40).
     */
    #[Test]
    public function runs_that_find_a_lock_with_no_owner_work_one_at_a_time(): void
    {
        $this->workers();
        $out = $this->shell(
            'mkdir "$LOCK"' . "\n"
            // Twelve: with six, one run in three got through the old takeover with nobody meeting.
            . 'for n in 1 2 3 4 5 6 7 8 9 10 11 12; do sh "$T/worker.sh" >/dev/null 2>&1 & done' . "\n"
            . 'sleep 0.2; touch "$T/start"; wait; sort "$T/log" | uniq -c | sed "s/^ *//"; [ -d "$LOCK" ] && echo held || echo free',
        );

        self::assertSame(['12 worked', 'free'], $out);
    }

    /**
     * That race, made to happen: this run reads "no owner", and before it takes
     * the lock over another run's owner file is there. The lock asks ls for the
     * directory between the two, so ls is where the other run gets in, at the
     * look that ends a grace of 1 s.
     */
    #[Test]
    public function an_owner_written_after_this_run_read_none_is_kept(): void
    {
        $out = $this->shell(
            'sleep 60 >/dev/null 2>&1 & other=$!' . "\n"
            . 'ls() {' . "\n"
            . '    echo look >> "$T/looks"' . "\n"
            . '    if [ "$(wc -l < "$T/looks" | tr -d " ")" = 11 ]; then printf "%s|%s|.\n" "$other" "$(_rl_started "$other")" > "$T/owner" && mv "$T/owner" "$LOCK/owner"; fi' . "\n"
            . '    command ls "$@"' . "\n"
            . '}' . "\n"
            . 'mkdir "$LOCK"; SEMITEXA_REGISTRY_LOCK_OWNERLESS_GRACE=1; SEMITEXA_REGISTRY_LOCK_WAIT=2' . "\n"
            . 'router_lock_acquire && echo "taken" || echo "refused"; echo "held=[$ROUTER_LOCK_HELD]"' . "\n"
            . 'IFS="|" read -r pid rest < "$LOCK/owner"; [ "$pid" = "$other" ] && echo "the other run still owns it"; kill "$other"',
        );

        self::assertSame(['refused', 'held=[]', 'the other run still owns it'], $out);
    }

    /**
     * A lock directory with no owner file: a run killed between the two steps,
     * or a bin/semitexa from before the lock named its owner. Nothing says who
     * holds it, so only a run that watched it stay so for the grace takes it.
     */
    #[Test]
    public function a_lock_with_no_owner_is_taken_over_only_after_the_grace(): void
    {
        $out = $this->shell(
            'mkdir "$LOCK"' . "\n"
            . 'router_lock_acquire 1 || echo "not at once"; [ -d "$LOCK" ] && echo "left"' . "\n"
            . 'SEMITEXA_REGISTRY_LOCK_OWNERLESS_GRACE=1; SEMITEXA_REGISTRY_LOCK_WAIT=10' . "\n"
            . 'router_lock_acquire && echo "taken"; echo "held=[$ROUTER_LOCK_HELD]"' . "\n"
            . 'router_lock_release; [ -d "$LOCK" ] && echo held || echo free',
        );

        self::assertSame(['not at once', 'left', 'taken', 'held=[1]', 'free'], $out);
    }

    /** Runs that wait for the lock, work under it for a moment and say so; "two at once" when another is at work. */
    private function workers(): void
    {
        $this->actor('worker', 'SEMITEXA_REGISTRY_LOCK_WAIT=20; SEMITEXA_REGISTRY_LOCK_OWNERLESS_GRACE=1' . "\n"
            . 'until [ -e "$T/start" ]; do :; done' . "\n"
            . 'if router_lock_acquire; then' . "\n"
            . '    [ -n "$ROUTER_LOCK_HELD" ] || echo "unlocked" >> "$T/log"' . "\n"
            . '    mkdir "$T/busy" 2>/dev/null || echo "two at once" >> "$T/log"' . "\n"
            . '    sleep 0.05; rmdir "$T/busy" 2>/dev/null; echo "worked" >> "$T/log"; router_lock_release' . "\n"
            . 'else echo "refused" >> "$T/log"; fi');
    }

    private function actor(string $name, string $body): void
    {
        file_put_contents($this->tmp . '/' . $name . '.sh', '. ' . escapeshellarg($this->tmp . '/lock.sh') . "\n" . $body . "\n");
    }

    /** @return list<string> the lines printed */
    private function shell(string $snippet): array
    {
        $sh = '. ' . escapeshellarg($this->tmp . '/lock.sh') . "\n" . $snippet;
        // A shell of its own, so its parent is above PID 1 whatever runs the suite.
        $raw = (string) shell_exec('env -i PATH=/usr/bin:/bin sh -c ' . escapeshellarg('sh -c "$0" 2>&1; :') . ' ' . escapeshellarg($sh));

        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    private static function function(string $name): string
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/bin/semitexa');
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n.*?^\}$/ms', $script, $m), $name . ' must still exist in bin/semitexa');

        return $m[0] ?? '';
    }
}
