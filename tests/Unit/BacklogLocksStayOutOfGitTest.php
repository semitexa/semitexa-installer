<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A new project commits var/ai-work, its backlog, and the scaffold .gitignore
 * decides what of it git takes. The stores there guard their writes with lock
 * files (charter.json.lock, tasks/.lock, decisions/.lock, state/scores.json.lock),
 * and the first make-it run of a fresh project committed two of them
 * (tk-scaffold-gitignore-commits-backlog-locks): the file ignored only the
 * agents' presence in that directory.
 *
 * Asked of git itself, in a repository holding the shipped file and the files
 * the backlog leaves behind, so the answer is the one a project gets.
 */
final class BacklogLocksStayOutOfGitTest extends TestCase
{
    private const GITIGNORE = __DIR__ . '/../../scaffold/.gitignore';

    private const LOCKS = [
        'var/ai-work/charter.json.lock',
        'var/ai-work/tasks/.lock',
        'var/ai-work/decisions/.lock',
        'var/ai-work/state/scores.json.lock',
    ];

    private const BACKLOG = [
        'var/ai-work/charter.json',
        'var/ai-work/activity.ndjson',
        'var/ai-work/epics/ep-first.json',
        'var/ai-work/tasks/tk-first.json',
        // A task may be about a lock: its id is no lock file.
        'var/ai-work/tasks/tk-release.lock-is-held.json',
        'var/ai-work/decisions/dec-first.json',
    ];

    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/semitexa-backlog-locks-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->project, 0775, true));
        self::assertTrue(copy(self::GITIGNORE, $this->project . '/.gitignore'));
        foreach ([...self::LOCKS, ...self::BACKLOG, 'composer.lock'] as $file) {
            $path = $this->project . '/' . $file;
            if (!is_dir(\dirname($path))) {
                self::assertTrue(mkdir(\dirname($path), 0775, true));
            }
            self::assertNotFalse(file_put_contents($path, "{}\n"));
        }
        $this->git('init -q');
    }

    protected function tearDown(): void
    {
        if ($this->project !== '') {
            exec('rm -rf ' . escapeshellarg($this->project));
        }
    }

    #[Test]
    public function git_status_of_a_used_backlog_names_no_lock_file(): void
    {
        $untracked = $this->untracked();

        self::assertSame([], array_values(array_intersect(self::LOCKS, $untracked)), 'git would commit these lock files with the backlog.');
        self::assertSame([], array_values(array_filter($untracked, static fn (string $path): bool => str_starts_with($path, 'var/ai-work/') && str_ends_with($path, '.lock'))));
    }

    #[Test]
    public function the_backlog_itself_is_still_offered_to_git(): void
    {
        $untracked = $this->untracked();

        self::assertSame([], array_values(array_diff(self::BACKLOG, $untracked)), 'The scaffold .gitignore hides part of the backlog.');
        self::assertContains('composer.lock', $untracked, 'Only the locks under var/ai-work are ignored: composer.lock is the project\'s.');
    }

    /**
     * @return list<string> every file git would add, relative to the project
     */
    private function untracked(): array
    {
        $lines = $this->git('status --porcelain --untracked-files=all');
        $paths = [];
        foreach ($lines as $line) {
            self::assertStringStartsWith('?? ', $line);
            $paths[] = substr($line, 3);
        }
        self::assertContains('.gitignore', $paths, 'git status listed nothing: the answer below would be an empty one.');

        return $paths;
    }

    /**
     * Git without the machine's own configuration: a global excludes file that
     * names *.lock would hide what the shipped file misses.
     *
     * @return list<string>
     */
    private function git(string $arguments): array
    {
        $command = sprintf(
            'env GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 git -C %s -c core.excludesFile=/dev/null -c core.quotePath=false %s 2>&1',
            escapeshellarg($this->project),
            $arguments,
        );
        exec($command, $output, $status);
        self::assertSame(0, $status, "git {$arguments} failed: " . implode("\n", $output));

        return $output;
    }
}
