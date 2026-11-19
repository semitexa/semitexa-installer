<?php

declare(strict_types=1);

/**
 * Writes resources/scaffold-manifest.json from semitexa/update's manifest.
 *
 * Until semitexa/update reads the scaffold from this package (step 2 of
 * tk-one-source-for-bin-semitexa), its resources/scaffold-manifest.json is the
 * source: `bin/semitexa scaffold:sync` rebuilds it, and it holds every sha256
 * each file ever shipped with. This tool copies from it, unchanged, the entry
 * of every file under scaffold/. It never computes a history: one built from
 * scratch would be empty, and a project still on an older file would then be
 * told it had edited it.
 *
 * Left in update's manifest: the agent docs (their source is semitexa/ultimate)
 * and the `retired` list.
 *
 * Usage, from the framework workspace, after `bin/semitexa scaffold:sync`:
 *
 *   php packages/semitexa-installer/tools/derive-manifest.php           write
 *   php packages/semitexa-installer/tools/derive-manifest.php --check   exit 1 when stale
 *   ... [--from=<path to update's scaffold-manifest.json>]
 */

$packageRoot = dirname(__DIR__);
$scaffoldRoot = $packageRoot . '/scaffold';
$target = $packageRoot . '/resources/scaffold-manifest.json';
$source = dirname($packageRoot) . '/semitexa-update/resources/scaffold-manifest.json';
$check = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;
    } elseif (str_starts_with($argument, '--from=')) {
        $source = substr($argument, strlen('--from='));
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(2);
};

$raw = is_file($source) ? file_get_contents($source) : false;
if ($raw === false) {
    $fail("Cannot read semitexa/update's manifest: {$source}");
}
$manifest = json_decode($raw, true);
if (!is_array($manifest) || !is_string($manifest['schema_version'] ?? null) || !is_string($manifest['generated_at'] ?? null) || !is_array($manifest['files'] ?? null)) {
    $fail("Not a scaffold manifest: {$source}");
}

$onDisk = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scaffoldRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $onDisk[substr($file->getPathname(), strlen($scaffoldRoot) + 1)] = true;
    }
}
if ($onDisk === []) {
    $fail("No files under {$scaffoldRoot}");
}

$entries = [];
foreach ($manifest['files'] as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $path = $entry['path'] ?? null;
    if (!is_string($path) || !isset($onDisk[$path])) {
        continue;
    }
    $hash = hash_file('sha256', $scaffoldRoot . '/' . $path);
    if (($entry['current_sha256'] ?? null) !== $hash) {
        $fail("{$path}: update's manifest does not describe the file in scaffold/ (run bin/semitexa scaffold:sync first).");
    }
    $entries[] = $entry;
    unset($onDisk[$path]);
}
if ($onDisk !== []) {
    $fail("No entry in update's manifest for: " . implode(', ', array_keys($onDisk)) . ' (run bin/semitexa scaffold:sync first).');
}

// The same encoding update:scaffold:rebuild writes, so an entry reads the same in both files.
$json = json_encode(
    ['schema_version' => $manifest['schema_version'], 'generated_at' => $manifest['generated_at'], 'files' => $entries],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . "\n";

$current = is_file($target) ? file_get_contents($target) : false;
if ($current === $json) {
    echo 'scaffold-manifest.json is current (' . count($entries) . " files).\n";
    exit(0);
}
if ($check) {
    fwrite(STDERR, "scaffold-manifest.json is stale: run php packages/semitexa-installer/tools/derive-manifest.php\n");
    exit(1);
}

$tmp = $target . '.tmp';
if (file_put_contents($tmp, $json) === false || !rename($tmp, $target)) {
    $fail("Cannot write {$target}");
}
echo 'Wrote scaffold-manifest.json (' . count($entries) . " files).\n";
