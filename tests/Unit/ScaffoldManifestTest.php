<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Semitexa\Installer\Application\Service\Scaffold;

/**
 * scaffold-manifest.json describes the files under scaffold/, and nothing has
 * been lost on the way here.
 *
 * `bin/semitexa update` replaces a project's file only when its sha256 is one
 * the framework shipped: the current one or one from the history. A file with
 * no entry is never updated; a wrong current hash makes every project look
 * edited; a history shorter than the one projects were released against tells
 * a project on an older file that it changed it, and its update stops at .new.
 */
final class ScaffoldManifestTest extends TestCase
{
    public function testTheLocatorPointsAtThisPackagesScaffoldAndManifest(): void
    {
        self::assertSame(\dirname(__DIR__, 2) . '/scaffold', Scaffold::directory());
        self::assertSame(\dirname(__DIR__, 2) . '/resources/scaffold-manifest.json', Scaffold::manifestPath());
        self::assertFileExists(Scaffold::directory() . '/bin/semitexa');
        self::assertFileExists(Scaffold::manifestPath());
    }

    public function testEveryScaffoldFileHasExactlyOneEntryAndEveryEntryAFile(): void
    {
        $files = $this->scaffoldFiles();
        self::assertContains('bin/semitexa', $files, 'The scan of scaffold/ found no bin/semitexa: it read the wrong directory or nothing.');
        self::assertContains('.env.default', $files, 'The scan of scaffold/ skipped dot files.');

        $paths = array_column($this->entries(Scaffold::manifestPath()), 'path');
        sort($paths);

        self::assertSame($files, $paths, 'scaffold-manifest.json and scaffold/ name different files: run php tools/derive-manifest.php after bin/semitexa scaffold:sync.');
    }

    public function testEveryEntryCarriesTheHashItsFileHasNow(): void
    {
        $entries = $this->entries(Scaffold::manifestPath());
        self::assertNotSame([], $entries);

        $stale = [];
        foreach ($entries as $entry) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['current_sha256'], $entry['path']);
            $actual = hash_file('sha256', Scaffold::directory() . '/' . $entry['path']);
            if ($actual !== $entry['current_sha256']) {
                $stale[] = $entry['path'];
            }
        }

        self::assertSame([], $stale, 'These files changed without their manifest entry: run bin/semitexa scaffold:sync, then php tools/derive-manifest.php.');
    }

    /**
     * The transition guard. Until semitexa/update reads this package's manifest
     * (step 2 of tk-one-source-for-bin-semitexa), update's own is what projects
     * are updated from, and this one must say the same about every scaffold
     * file: same history, in the same order, same policy fields. It goes away
     * with update's resources/scaffold-manifest.json.
     */
    public function testEveryEntryIsTheOneSemitexaUpdateShips(): void
    {
        $ours = $this->entries(Scaffold::manifestPath());
        $theirs = [];
        foreach ($this->entries($this->updateManifestPath()) as $entry) {
            $theirs[$entry['path']] = $entry;
        }

        $historyHashes = 0;
        foreach ($ours as $entry) {
            $path = $entry['path'];
            self::assertArrayHasKey($path, $theirs, "{$path} has no entry in semitexa/update's manifest.");
            self::assertSame($theirs[$path]['previous_sha256'], $entry['previous_sha256'], "{$path}: the history differs from the one semitexa/update ships.");
            self::assertSame($theirs[$path], $entry, "{$path}: the entry differs from the one semitexa/update ships.");
            $historyHashes += \count($entry['previous_sha256']);
        }

        // bin/semitexa alone has shipped in dozens of versions: a history that
        // came out empty on both sides would otherwise compare equal.
        self::assertGreaterThan(100, $historyHashes, 'The history is all but empty: it was regenerated, not carried over.');
        $bin = $theirs['bin/semitexa'] ?? null;
        self::assertNotNull($bin);
        self::assertGreaterThan(10, \count($bin['previous_sha256']), 'bin/semitexa has almost no history.');
    }

    /**
     * @return list<string> paths relative to scaffold/, sorted
     */
    private function scaffoldFiles(): array
    {
        $root = Scaffold::directory();
        self::assertDirectoryExists($root);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<array{path: string, current_sha256: string, previous_sha256: list<string>}&array<string, mixed>>
     */
    private function entries(string $manifestPath): array
    {
        self::assertFileExists($manifestPath);
        $data = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($data, "{$manifestPath} is not JSON.");
        self::assertIsArray($data['files'] ?? null, "{$manifestPath} has no files list.");
        self::assertNotSame([], $data['files'], "{$manifestPath} lists no files.");

        $entries = [];
        foreach ($data['files'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['path'] ?? null);
            self::assertNotSame('', $entry['path']);
            self::assertIsString($entry['current_sha256'] ?? null, $entry['path']);
            self::assertIsArray($entry['previous_sha256'] ?? null, $entry['path']);
            self::assertTrue(array_is_list($entry['previous_sha256']), $entry['path']);
            foreach ($entry['previous_sha256'] as $hash) {
                self::assertIsString($hash, $entry['path']);
            }
            /** @var array{path: string, current_sha256: string, previous_sha256: list<string>}&array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * semitexa/update's manifest, wherever the autoloader has that package:
     * a sibling checkout in the workspace, vendor/ elsewhere.
     */
    private function updateManifestPath(): string
    {
        $class = 'Semitexa\\Update\\Application\\Service\\UpdateRunnerFactory';
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);
            if (\is_string($file)) {
                $real = realpath($file);
                self::assertIsString($real);

                // <package>/src/Application/Service/UpdateRunnerFactory.php
                return \dirname($real, 4) . '/resources/scaffold-manifest.json';
            }
        }

        self::fail('semitexa/update is not installed: this guard compares the manifest with the one it ships, and has nothing to compare against.');
    }
}
