<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tools/check-manifest.php: the manifest semitexa/update will read from this
 * package describes scaffold/ in a form every released update reads, and keeps
 * every hash an earlier manifest knew.
 *
 * Each defect below is one that would reach every project once update reads
 * this manifest (step 2 of tk-one-source-for-bin-semitexa; Fable's review of
 * the plan, D2 and D3). The tool runs on a copy of this package's scaffold and
 * manifest, changed in one way per case.
 *
 * @phpstan-type Entry array{path: string, current_sha256: string, previous_sha256: list<string>, category: string, critical: bool, auto_update: bool, preserve_executable: bool, notes: string}
 * @phpstan-type Manifest array{schema_version: string, generated_at: string, files: list<Entry>}
 */
final class CheckManifestToolTest extends TestCase
{
    private const TOOL = __DIR__ . '/../../tools/check-manifest.php';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-check-manifest-' . bin2hex(random_bytes(6));
        $package = \dirname(__DIR__, 2);
        mkdir($this->root . '/resources', 0755, true);
        copy($package . '/resources/scaffold-manifest.json', $this->root . '/resources/scaffold-manifest.json');
        $this->copyTree($package . '/scaffold', $this->root . '/scaffold');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheShippedScaffoldAndManifestPass(): void
    {
        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('describes scaffold/ (19 files)', $output);
    }

    /**
     * @param \Closure(string): void $break
     */
    #[DataProvider('defects')]
    public function testEachDefectIsNamed(\Closure $break, string $finding): void
    {
        $break($this->root);

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString($finding, $output);
    }

    /**
     * @return iterable<string, array{\Closure(string): void, string}>
     */
    public static function defects(): iterable
    {
        yield 'a file changed and its entry did not' => [
            static function (string $root): void { file_put_contents($root . '/scaffold/bin/semitexa', "\n# edited\n", FILE_APPEND); },
            'bin/semitexa: the file hashes to',
        ];
        yield 'a new file with no entry' => [
            static function (string $root): void { file_put_contents($root . '/scaffold/docker-compose.new.yml', "services: {}\n"); },
            'docker-compose.new.yml: in scaffold/ with no manifest entry',
        ];
        yield 'an entry whose file is gone' => [
            static function (string $root): void { unlink($root . '/scaffold/docker-compose.redis.yml'); },
            'docker-compose.redis.yml: listed in the manifest with no file in scaffold/',
        ];
        yield 'a schema version no update reads' => [
            static function (string $root): void { self::edit($root, static function (array $m): array { $m['schema_version'] = 'semitexa.scaffold-manifest/v2'; return $m; }); },
            'schema_version is "semitexa.scaffold-manifest/v2"',
        ];
        yield 'no generated_at' => [
            static function (string $root): void { self::edit($root, static function (array $m): array { unset($m['generated_at']); return $m; }); },
            'generated_at is missing',
        ];
        yield 'a category no update knows' => [
            static function (string $root): void { self::editEntry($root, 'Dockerfile', static function (array $e): array { $e['category'] = 'container'; return $e; }); },
            'Dockerfile: category "container" is not one semitexa/update knows',
        ];
        yield 'a flag that is not a boolean' => [
            static function (string $root): void { self::editEntry($root, 'bin/semitexa', static function (array $e): array { $e['critical'] = 'yes'; return $e; }); },
            'bin/semitexa: critical is not true or false',
        ];
        yield 'a history hash that is not a sha256' => [
            static function (string $root): void { self::editEntry($root, 'bin/semitexa', static function (array $e): array { $e['previous_sha256'][] = str_repeat('a', 63); return $e; }); },
            'which is not a sha256',
        ];
        yield 'the current hash repeated in the history' => [
            static function (string $root): void { self::editEntry($root, 'bin/semitexa', static function (array $e): array { $e['previous_sha256'][] = $e['current_sha256']; return $e; }); },
            'bin/semitexa: previous_sha256 repeats the current hash',
        ];
        yield 'a history hash listed twice' => [
            static function (string $root): void { self::editEntry($root, 'bin/semitexa', static function (array $e): array { $e['previous_sha256'][] = $e['previous_sha256'][0]; return $e; }); },
            'bin/semitexa: previous_sha256 lists a hash twice',
        ];
        yield 'a path listed twice' => [
            static function (string $root): void { self::edit($root, static function (array $m): array { $m['files'][] = $m['files'][0]; return $m; }); },
            ': listed twice',
        ];
        yield 'a path that leaves the project' => [
            static function (string $root): void { self::edit($root, static function (array $m): array { $m['files'][0]['path'] = '../outside'; return $m; }); },
            'not a safe relative path: "../outside"',
        ];
    }

    public function testAHashTheEarlierManifestKnewMustStillBeKnown(): void
    {
        $earlier = $this->root . '/earlier.json';
        copy($this->root . '/resources/scaffold-manifest.json', $earlier);
        $dropped = '';
        self::editEntry($this->root, 'bin/semitexa', static function (array $e) use (&$dropped): array {
            $dropped = (string) array_pop($e['previous_sha256']);
            return $e;
        });
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $dropped, 'bin/semitexa had no history to drop');

        [$status, $output] = $this->check('--previous=' . $earlier);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString("bin/semitexa: {$dropped} was known to the earlier manifest and is not any more", $output);
    }

    public function testAPathTheEarlierManifestListedMustStillBeListed(): void
    {
        $earlier = $this->root . '/earlier.json';
        copy($this->root . '/resources/scaffold-manifest.json', $earlier);
        unlink($this->root . '/scaffold/docker-compose.nats.yml');
        self::edit($this->root, static function (array $m): array {
            $m['files'] = array_values(array_filter($m['files'], static fn (array $e): bool => $e['path'] !== 'docker-compose.nats.yml'));
            return $m;
        });

        [$status, $output] = $this->check('--previous=' . $earlier);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('docker-compose.nats.yml: the earlier manifest lists it and this one neither lists nor retires it', $output);
    }

    public function testAFileTakenOutOnPurposeIsRetiredWithEveryHashItShippedWith(): void
    {
        $earlier = $this->root . '/earlier.json';
        copy($this->root . '/resources/scaffold-manifest.json', $earlier);
        $shipped = [];
        foreach (self::read($earlier)['files'] as $entry) {
            if ($entry['path'] === 'docker-compose.nats.yml') {
                $shipped = [$entry['current_sha256'], ...$entry['previous_sha256']];
            }
        }
        self::assertNotSame([], $shipped, 'docker-compose.nats.yml is not in the manifest: the case would retire nothing.');
        unlink($this->root . '/scaffold/docker-compose.nats.yml');
        /**
         * @param list<string> $hashes
         * @return \Closure(Manifest): array<mixed>
         */
        $retire = static function (array $hashes): \Closure {
            return static function (array $m) use ($hashes): array {
                $kept = [];
                foreach (is_array($m['files'] ?? null) ? $m['files'] : [] as $entry) {
                    if (is_array($entry) && ($entry['path'] ?? null) !== 'docker-compose.nats.yml') {
                        $kept[] = $entry;
                    }
                }
                $m['files'] = $kept;
                $m['retired'] = [['path' => 'docker-compose.nats.yml', 'shipped_sha256' => $hashes]];
                return $m;
            };
        };

        self::edit($this->root, $retire($shipped));
        [$status, $output] = $this->check('--previous=' . $earlier);
        self::assertSame(0, $status, $output);

        // Retired without one of its versions, a project still on it would keep the file.
        self::edit($this->root, $retire(array_slice($shipped, 1)));
        [$status, $output] = $this->check('--previous=' . $earlier);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString("docker-compose.nats.yml: {$shipped[0]} was known to the earlier manifest and is not any more", $output);
    }

    public function testARetiredPathTheScaffoldStillShipsIsRefused(): void
    {
        self::edit($this->root, static function (array $m): array {
            $m['retired'] = [['path' => 'bin/semitexa', 'shipped_sha256' => [str_repeat('c', 64)]]];
            return $m;
        });

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('bin/semitexa: both shipped and retired', $output);
    }

    public function testAManifestThatIsNotJsonCannotBeRead(): void
    {
        file_put_contents($this->root . '/resources/scaffold-manifest.json', '{"files": [');

        [$status, $output] = $this->check();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString('Not JSON', $output);
    }

    /**
     * The durable half of D2: nothing the last tag's manifest knew is lost on
     * this line. Tags sit on master's merge commits, which develop does not
     * contain, so the newest is found by version, not by `git describe`.
     */
    public function testThisLineKeepsEveryHashOfTheLastTag(): void
    {
        $package = \dirname(__DIR__, 2);
        exec(sprintf('git -C %s tag -l %s --sort=-version:refname 2>&1', escapeshellarg($package), escapeshellarg('20*')), $tags, $status);
        self::assertSame(0, $status, 'git could not list the tags of ' . $package . ': ' . implode("\n", $tags));
        self::assertNotSame([], $tags, 'This checkout has no release tags: fetch them (git fetch --tags) to check the history against the last release.');
        $earlier = $this->root . '/last-tag.json';
        exec(sprintf('git -C %s show %s 2>&1', escapeshellarg($package), escapeshellarg($tags[0] . ':resources/scaffold-manifest.json')), $lines, $status);
        self::assertSame(0, $status, "The last tag {$tags[0]} has no manifest: " . implode("\n", $lines));
        file_put_contents($earlier, implode("\n", $lines) . "\n");

        // Checked on the package itself, not the copy: this is about the line.
        exec(sprintf('%s %s --previous=%s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::TOOL), escapeshellarg($earlier)), $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('keeps every hash of the earlier manifest', implode("\n", $output));
    }

    /**
     * @return array{int, string}
     */
    private function check(string ...$arguments): array
    {
        $command = sprintf('%s %s --root=%s', escapeshellarg(PHP_BINARY), escapeshellarg(self::TOOL), escapeshellarg($this->root));
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * @param \Closure(Manifest): array<mixed> $change
     */
    private static function edit(string $root, \Closure $change): void
    {
        $path = $root . '/resources/scaffold-manifest.json';
        file_put_contents($path, json_encode($change(self::read($path)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * The manifest in the shape the cases change it in; a field of another type fails the case here.
     *
     * @return Manifest
     */
    private static function read(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            self::fail("{$path} has no files list.");
        }
        $files = [];
        foreach ($data['files'] as $entry) {
            if (!is_array($entry) || !is_array($entry['previous_sha256'] ?? null)) {
                self::fail("{$path}: an entry without a history list.");
            }
            $history = [];
            foreach ($entry['previous_sha256'] as $hash) {
                $history[] = self::text($hash, 'previous_sha256');
            }
            $files[] = [
                'path' => self::text($entry['path'] ?? null, 'path'),
                'current_sha256' => self::text($entry['current_sha256'] ?? null, 'current_sha256'),
                'previous_sha256' => $history,
                'category' => self::text($entry['category'] ?? null, 'category'),
                'critical' => self::flag($entry['critical'] ?? null, 'critical'),
                'auto_update' => self::flag($entry['auto_update'] ?? null, 'auto_update'),
                'preserve_executable' => self::flag($entry['preserve_executable'] ?? null, 'preserve_executable'),
                'notes' => self::text($entry['notes'] ?? null, 'notes'),
            ];
        }

        return [
            'schema_version' => self::text($data['schema_version'] ?? null, 'schema_version'),
            'generated_at' => self::text($data['generated_at'] ?? null, 'generated_at'),
            'files' => $files,
        ];
    }

    private static function text(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            self::fail("{$field} is not a string in the manifest the case starts from.");
        }

        return $value;
    }

    private static function flag(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            self::fail("{$field} is not a boolean in the manifest the case starts from.");
        }

        return $value;
    }

    /**
     * @param \Closure(Entry): array<mixed> $change
     */
    private static function editEntry(string $root, string $path, \Closure $change): void
    {
        $found = false;
        self::edit($root, static function (array $manifest) use ($path, $change, &$found): array {
            foreach ($manifest['files'] as $i => $entry) {
                if ($entry['path'] === $path) {
                    $manifest['files'][$i] = $change($entry);
                    $found = true;
                }
            }
            return $manifest;
        });
        self::assertTrue($found, "No entry for {$path} in the copy: the case would change nothing.");
    }

    private function copyTree(string $from, string $to): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        mkdir($to, 0755, true);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $target = $to . '/' . substr($item->getPathname(), \strlen($from) + 1);
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
