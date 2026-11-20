<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
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
    // verify:accept-test-change the comparison with semitexa/update's manifest ends with update's step 2b: update ships none of these entries now, and CheckManifestToolTest checks this manifest and its history against the last release tag
    private const SHA256 = '/^[0-9a-f]{64}$/';

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

        self::assertSame($files, $paths, 'scaffold-manifest.json and scaffold/ name different files: run bin/semitexa scaffold:sync, which rebuilds the manifest.');
    }

    public function testEveryEntryCarriesTheHashItsFileHasNow(): void
    {
        $entries = $this->entries(Scaffold::manifestPath());
        self::assertNotSame([], $entries);

        $stale = [];
        foreach ($entries as $entry) {
            $actual = hash_file('sha256', Scaffold::directory() . '/' . $entry['path']);
            if ($actual !== $entry['current_sha256']) {
                $stale[] = $entry['path'];
            }
        }

        self::assertSame([], $stale, 'These files changed without their manifest entry: run bin/semitexa scaffold:sync, which rebuilds the manifest.');
    }

    /**
     * The reader every test here goes through refuses a hash that is not one:
     * with it, an entry would pass every comparison while naming no file.
     */
    #[DataProvider('valuesThatAreNotASha256')]
    public function testAManifestWithAHashThatIsNotASha256IsRefused(string $field, string $value): void
    {
        $valid = str_repeat('a', 64);
        $entry = ['path' => 'bin/semitexa', 'current_sha256' => $valid, 'previous_sha256' => [$valid]];
        if ($field === 'current_sha256') {
            $entry['current_sha256'] = $value;
        } else {
            $entry['previous_sha256'][] = $value;
        }

        $path = tempnam(sys_get_temp_dir(), 'scaffold-manifest-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode(['files' => [$entry]], JSON_THROW_ON_ERROR));
            $refusal = null;
            try {
                $this->entries($path);
            } catch (ExpectationFailedException $e) {
                $refusal = $e->getMessage();
            }
        } finally {
            unlink($path);
        }

        self::assertNotNull($refusal, "A {$field} of '{$value}' was read as a hash.");
        self::assertStringContainsString($field, $refusal);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatAreNotASha256(): iterable
    {
        foreach (['current_sha256', 'previous_sha256'] as $field) {
            yield "{$field}: empty" => [$field, ''];
            yield "{$field}: cut off" => [$field, str_repeat('a', 63)];
            yield "{$field}: too long" => [$field, str_repeat('a', 65)];
            yield "{$field}: not hexadecimal" => [$field, str_repeat('g', 64)];
            yield "{$field}: upper case" => [$field, str_repeat('A', 64)];
        }
    }

    public function testAnEntryWithNoHistoryIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scaffold-manifest-');
        self::assertIsString($path);
        try {
            file_put_contents($path, json_encode(['files' => [['path' => 'new-file', 'current_sha256' => str_repeat('b', 64), 'previous_sha256' => []]]], JSON_THROW_ON_ERROR));
            $entries = $this->entries($path);
        } finally {
            unlink($path);
        }

        self::assertCount(1, $entries);
        self::assertSame([], $entries[0]['previous_sha256']);
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
            self::assertMatchesRegularExpression(self::SHA256, $entry['current_sha256'], $entry['path'] . ': current_sha256 is not a sha256.');
            self::assertIsArray($entry['previous_sha256'] ?? null, $entry['path']);
            self::assertTrue(array_is_list($entry['previous_sha256']), $entry['path']);
            foreach ($entry['previous_sha256'] as $hash) {
                self::assertIsString($hash, $entry['path']);
                // An empty or cut-off hash identifies no released file, and
                // the same one in both manifests would still compare equal.
                self::assertMatchesRegularExpression(self::SHA256, $hash, $entry['path'] . ': previous_sha256 holds a value that is not a sha256.');
            }
            /** @var array{path: string, current_sha256: string, previous_sha256: list<string>}&array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }
}
