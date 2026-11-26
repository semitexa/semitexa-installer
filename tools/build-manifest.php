<?php

declare(strict_types=1);

/*
 * Writes resources/scaffold-manifest.json from scaffold/ and
 * resources/scaffold-policy.php, keeping every hash the manifest knew.
 *
 * Step 2.5 of one source for bin/semitexa: the installer builds its own
 * manifest, as plain PHP with no autoloader. Until now semitexa/update's
 * update:scaffold:rebuild wrote it from update's own builder and policy, so a
 * new scaffold file was two PRs (a row here, a row there). The rules are that
 * builder's, unchanged:
 *   - every file in scaffold/ needs a policy row: an uncategorised one stops
 *     the build, a human decides its policy first;
 *   - a file whose content changed keeps its old hash in previous_sha256, so
 *     a project that still has that version is updated, not called edited;
 *   - a retired path shipped again also keeps the hashes it shipped with,
 *     and the retired list is carried over as it is otherwise;
 *   - generated_at changes only when the files do.
 *
 * Usage:
 *   php tools/build-manifest.php           write it when it is stale
 *   php tools/build-manifest.php --check   exit 1 when it is stale, write nothing
 *   --root=<dir>                           another copy of this package (tests)
 *
 * Exit 0: current (or written). 1: stale under --check. 2: something could not
 * be read or written, or a scaffold file has no policy row.
 */

const SCHEMA_VERSION = 'semitexa.scaffold-manifest/v1';

$root = dirname(__DIR__);
$check = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;
        continue;
    }
    // Another copy of the package, as check-manifest.php takes it (tests).
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

/** @return list<string> scaffold-relative paths, sorted */
function scaffoldFiles(string $scaffold): array
{
    if (!is_dir($scaffold)) {
        fail("No scaffold directory: {$scaffold}");
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scaffold, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($scaffold) + 1));
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

$scaffold = $root . '/scaffold';
$manifestPath = $root . '/resources/scaffold-manifest.json';
$policyPath = $root . '/resources/scaffold-policy.php';

$read = is_file($policyPath) ? require $policyPath : null;
if (!is_array($read)) {
    fail("Cannot read the scaffold policy: {$policyPath}");
}
/** @var array<string, array{category: string, critical: bool, auto_update: bool, preserve_executable: bool, notes: string}> $policy */
$policy = [];
foreach ($read as $path => $row) {
    if (!is_string($path) || !is_array($row) || !is_string($row['category'] ?? null) || !is_bool($row['critical'] ?? null)
        || !is_bool($row['auto_update'] ?? null) || !is_bool($row['preserve_executable'] ?? null) || !is_string($row['notes'] ?? null)) {
        fail("The scaffold policy has a row that is not {category, critical, auto_update, preserve_executable, notes}: " . (is_string($path) ? $path : '?'));
    }
    $policy[$path] = ['category' => $row['category'], 'critical' => $row['critical'], 'auto_update' => $row['auto_update'], 'preserve_executable' => $row['preserve_executable'], 'notes' => $row['notes']];
}

$prior = null;
if (is_file($manifestPath)) {
    $raw = @file_get_contents($manifestPath);
    $prior = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($prior) || !is_array($prior['files'] ?? null)) {
        fail("Cannot read the manifest it would extend: {$manifestPath}");
    }
}

/** @var array<string, array<string, mixed>> $priorEntries */
$priorEntries = [];
foreach ($prior['files'] ?? [] as $entry) {
    if (is_array($entry) && is_string($entry['path'] ?? null)) {
        $priorEntries[$entry['path']] = $entry;
    }
}
/** @var array<string, list<string>> $priorRetired */
$priorRetired = [];
foreach (is_array($prior['retired'] ?? null) ? $prior['retired'] : [] as $entry) {
    if (is_array($entry) && is_string($entry['path'] ?? null) && is_array($entry['shipped_sha256'] ?? null)) {
        $priorRetired[$entry['path']] = array_values(array_filter($entry['shipped_sha256'], 'is_string'));
    }
}

$paths = scaffoldFiles($scaffold);
$unknown = array_values(array_diff($paths, array_keys($policy)));
if ($unknown !== []) {
    fail('Scaffold contains uncategorised files (add them to resources/scaffold-policy.php first): ' . implode(', ', $unknown));
}

$files = [];
foreach ($paths as $path) {
    $current = hash_file('sha256', $scaffold . '/' . $path);
    if (!is_string($current)) {
        fail("Cannot hash scaffold/{$path}");
    }
    $previous = [];
    $before = $priorEntries[$path] ?? null;
    if ($before !== null) {
        $known = array_values(array_filter(is_array($before['previous_sha256'] ?? null) ? $before['previous_sha256'] : [], 'is_string'));
        $was = is_string($before['current_sha256'] ?? null) ? $before['current_sha256'] : '';
        $previous = $was === $current ? $known : [$was, ...$known];
        $previous = array_values(array_unique(array_filter(
            $previous,
            static fn (string $hash): bool => $hash !== '' && $hash !== $current,
        )));
    }
    // A retired path shipped again: the versions it shipped with before stay
    // known, so an untouched old copy is replaced, not reported as edited.
    if (isset($priorRetired[$path])) {
        $previous = array_values(array_unique([...$previous, ...array_diff($priorRetired[$path], [$current])]));
    }
    $row = $policy[$path];
    $files[] = [
        'path' => $path,
        'current_sha256' => $current,
        'previous_sha256' => $previous,
        'category' => $row['category'],
        'critical' => $row['critical'],
        'auto_update' => $row['auto_update'],
        'preserve_executable' => $row['preserve_executable'],
        'notes' => $row['notes'],
    ];
}

// Carried over as it is, plus any previously active path removed from
// scaffold/ so its shipped hashes are preserved under retired; a path
// shipped again is managed again under files.
foreach (array_diff(array_keys($priorEntries), $paths) as $removedPath) {
    $removedEntry = $priorEntries[$removedPath];
    $removedKnown = is_array($removedEntry['previous_sha256'] ?? null) ? $removedEntry['previous_sha256'] : [];
    $removedHashes = array_values(array_filter(
        [$removedEntry['current_sha256'] ?? null, ...$removedKnown, ...($priorRetired[$removedPath] ?? [])],
        static fn (mixed $hash): bool => is_string($hash) && $hash !== '',
    ));
    if ($removedHashes !== []) {
        $priorRetired[$removedPath] = array_values(array_unique($removedHashes));
    }
}

$retired = [];
$retiredPaths = array_values(array_diff(array_keys($priorRetired), $paths));
sort($retiredPaths, SORT_STRING);
foreach ($retiredPaths as $path) {
    $hashes = array_values(array_unique(array_filter(
        $priorRetired[$path],
        static fn (string $hash): bool => $hash !== '',
    )));
    if ($hashes === []) {
        continue;
    }
    sort($hashes, SORT_STRING);
    $retired[] = ['path' => $path, 'shipped_sha256' => $hashes];
}

if ($prior !== null && $prior['files'] === $files && ($prior['retired'] ?? []) === $retired) {
    echo "scaffold-manifest.json is current (" . count($files) . " files).\n";
    exit(0);
}
if ($check) {
    fwrite(STDERR, "scaffold-manifest.json is stale: run php tools/build-manifest.php and commit it with the scaffold change.\n");
    exit(1);
}

$manifest = [
    'schema_version' => SCHEMA_VERSION,
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'files' => $files,
    'retired' => $retired,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$tmp = $manifestPath . '.tmp.' . getmypid();
if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $manifestPath)) {
    @unlink($tmp);
    fail("Cannot write {$manifestPath}");
}
echo "scaffold-manifest.json written (" . count($files) . " files).\n";
