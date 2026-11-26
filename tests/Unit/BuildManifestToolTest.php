<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/build-manifest.php: the installer writes its own scaffold manifest from
 * scaffold/ and resources/scaffold-policy.php (step 2.5 of one source for
 * bin/semitexa), by the rules semitexa/update's ScaffoldManifestBuilder had.
 * Every case runs the tool on a copy of this package.
 */
final class BuildManifestToolTest extends TestCase
{
    private const BUILD = __DIR__ . '/../../tools/build-manifest.php';
    private const CHECK = __DIR__ . '/../../tools/check-manifest.php';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-build-manifest-' . bin2hex(random_bytes(6));
        $package = \dirname(__DIR__, 2);
        mkdir($this->root . '/resources', 0755, true);
        copy($package . '/resources/scaffold-manifest.json', $this->root . '/resources/scaffold-manifest.json');
        copy($package . '/resources/scaffold-policy.php', $this->root . '/resources/scaffold-policy.php');
        exec('cp -R ' . escapeshellarg($package . '/scaffold') . ' ' . escapeshellarg($this->root . '/scaffold'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** The parity proof: from the shipped scaffold and policy it writes exactly the manifest update's builder wrote. */
    public function testTheShippedManifestIsWhatItWouldWrite(): void
    {
        $before = (string) file_get_contents($this->root . '/resources/scaffold-manifest.json');

        [$status, $output] = $this->run_('--check');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('is current (19 files)', $output);

        [$status, $output] = $this->run_();
        self::assertSame(0, $status, $output);
        self::assertSame($before, file_get_contents($this->root . '/resources/scaffold-manifest.json'), 'nothing to write, not even generated_at');
    }

    public function testAChangedFileKeepsItsOldHashAndPassesTheCheck(): void
    {
        $old = $this->entry('bin/semitexa');
        $generatedBefore = $this->manifest()['generated_at'] ?? '';
        self::assertNotSame('', $generatedBefore);
        file_put_contents($this->root . '/scaffold/bin/semitexa', "\n# changed\n", FILE_APPEND);

        [$status, $output] = $this->run_('--check');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('is stale', $output);

        [$status, $output] = $this->run_();
        self::assertSame(0, $status, $output);
        $new = $this->entry('bin/semitexa');
        self::assertSame(hash_file('sha256', $this->root . '/scaffold/bin/semitexa'), $new['current_sha256']);
        self::assertSame([$old['current_sha256'], ...$old['previous_sha256']], $new['previous_sha256'], 'a project on the old version is updated, not called edited');
        self::assertNotSame($generatedBefore, $this->manifest()['generated_at'] ?? $generatedBefore, 'the files changed, so generated_at does');

        exec(sprintf('%s %s --root=%s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::CHECK), escapeshellarg($this->root)), $checked, $checkStatus);
        self::assertSame(0, $checkStatus, implode("\n", $checked));
    }

    public function testAFileWithNoPolicyRowStopsTheBuild(): void
    {
        file_put_contents($this->root . '/scaffold/docker-compose.new.yml', "services: {}\n");
        $before = (string) file_get_contents($this->root . '/resources/scaffold-manifest.json');

        [$status, $output] = $this->run_();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString('uncategorised files (add them to resources/scaffold-policy.php first): docker-compose.new.yml', $output);
        self::assertSame($before, file_get_contents($this->root . '/resources/scaffold-manifest.json'));
    }

    public function testANewFileWithARowJoinsWithNoHistory(): void
    {
        file_put_contents($this->root . '/scaffold/docker-compose.new.yml', "services: {}\n");
        $policy = require $this->root . '/resources/scaffold-policy.php';
        self::assertIsArray($policy);
        $redis = $policy['docker-compose.redis.yml'] ?? null;
        self::assertIsArray($redis);
        $policy['docker-compose.new.yml'] = $redis;
        file_put_contents($this->root . '/resources/scaffold-policy.php', "<?php\n\nreturn " . var_export($policy, true) . ";\n");

        [$status, $output] = $this->run_();

        self::assertSame(0, $status, $output);
        $entry = $this->entry('docker-compose.new.yml');
        self::assertSame([], $entry['previous_sha256']);
        self::assertSame($redis['category'] ?? null, $entry['category']);
    }

    /** update#116's rules: a retired path stays retired, and one shipped again keeps the hashes it shipped with. */
    public function testRetiredPathsAreCarriedOverOrComeBackWithTheirHistory(): void
    {
        $manifest = $this->manifest();
        $manifest['retired'] = [
            ['path' => 'docker-compose.gone.yml', 'shipped_sha256' => [str_repeat('b', 64)]],
            ['path' => 'docker-compose.redis.yml', 'shipped_sha256' => [str_repeat('c', 64)]],
        ];
        $manifest['files'] = array_values(array_filter($manifest['files'], static fn (array $e): bool => $e['path'] !== 'docker-compose.redis.yml'));
        file_put_contents($this->root . '/resources/scaffold-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        [$status, $output] = $this->run_();

        self::assertSame(0, $status, $output);
        $written = $this->manifest();
        self::assertSame([['path' => 'docker-compose.gone.yml', 'shipped_sha256' => [str_repeat('b', 64)]]], $written['retired'] ?? null, 'carried over as it is; the re-shipped one leaves the list');
        self::assertSame([str_repeat('c', 64)], $this->entry('docker-compose.redis.yml')['previous_sha256'], 'its old version stays known');
    }

    /**
     * @return array{int, string}
     */
    private function run_(string ...$arguments): array
    {
        $command = sprintf('%s %s --root=%s', escapeshellarg(PHP_BINARY), escapeshellarg(self::BUILD), escapeshellarg($this->root));
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /** @return array{files: list<array<string, mixed>>, retired?: list<array<string, mixed>>, generated_at?: string} */
    private function manifest(): array
    {
        $data = json_decode((string) file_get_contents($this->root . '/resources/scaffold-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['files'] ?? null);

        /** @var array{files: list<array<string, mixed>>, retired?: list<array<string, mixed>>, generated_at?: string} $data */
        return $data;
    }

    /** @return array{current_sha256: string, previous_sha256: list<string>, category: string} */
    private function entry(string $path): array
    {
        foreach ($this->manifest()['files'] as $entry) {
            if (($entry['path'] ?? null) === $path) {
                /** @var array{current_sha256: string, previous_sha256: list<string>, category: string} $entry */
                return $entry;
            }
        }
        self::fail("{$path} is not in the manifest");
    }
}
