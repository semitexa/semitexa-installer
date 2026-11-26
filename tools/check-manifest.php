<?php

declare(strict_types=1);

/**
 * Checks that resources/scaffold-manifest.json describes scaffold/ in a form
 * every semitexa/update can read, and, given an earlier manifest, that it still
 * knows every hash that one knew.
 *
 * semitexa/update replaces a project's scaffold file only when its sha256 is
 * one the framework shipped. From step 2 of tk-one-source-for-bin-semitexa it
 * reads this manifest from vendor/semitexa/installer, so a defect here reaches
 * every project:
 *   - a file with no entry is never updated;
 *   - a wrong current hash makes every project look edited;
 *   - a hash dropped from the history tells a project on that version that it
 *     edited the file, and its update stops at manual review;
 *   - a schema or category the installed update does not know stops its
 *     update (Fable's review of the step 2 plan, D3), so only the ones every
 *     released update reads pass here.
 *
 * It only reads files, and needs no autoloader: the release stage runs it on
 * the host before any container is up.
 *
 * Usage:
 *   php tools/check-manifest.php [--root=<package root>] [--previous=<manifest>]
 *
 * --previous names an earlier manifest, the one at the last tag: every path it
 * lists must still be listed, and every hash it knew for a path must still be
 * known for it, as the current one or in the history. A file taken out of the
 * scaffold on purpose goes to `retired` with every hash it shipped with:
 * semitexa/update then deletes a project's copy that still has one of them.
 *
 * Exit 0 when it holds, 1 with each finding on stderr, 2 when a file it needs
 * cannot be read.
 */

const SCHEMA_VERSION = 'semitexa.scaffold-manifest/v1';

/** ScaffoldFileCategory in semitexa/update: a category it does not know refuses the whole manifest. */
const CATEGORIES = ['executable', 'env_template', 'infrastructure', 'vcs', 'example', 'analysis', 'agent_docs', 'agent_skill'];

const FLAGS = ['critical', 'auto_update', 'preserve_executable'];

$root = dirname(__DIR__);
$previousPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, strlen('--root=')), '/');
    } elseif (str_starts_with($argument, '--previous=')) {
        $previousPath = substr($argument, strlen('--previous='));
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

$cannotRead = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(2);
};

/** @return array<mixed> */
$readJson = static function (string $path) use ($cannotRead): array {
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        $cannotRead("Cannot read {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $cannotRead("Not JSON: {$path}");
    }

    return $data;
};

/** A value as the manifest writes it, slashes unescaped, for a finding a person reads. */
$show = static fn (mixed $value): string => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
/** The value when it is a sha256 in the form the manifest writes it (64 lower-case hex digits), null otherwise. */
$sha256 = static fn (mixed $value): ?string => is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
$isSafePath = static fn (string $path): bool => $path !== ''
    && !str_starts_with($path, '/')
    && !in_array('..', explode('/', $path), true)
    && !in_array('', explode('/', $path), true)
    && !str_contains($path, '\\');

$scaffoldRoot = $root . '/scaffold';
$manifestPath = $root . '/resources/scaffold-manifest.json';
if (!is_dir($scaffoldRoot)) {
    $cannotRead("No scaffold directory: {$scaffoldRoot}");
}
$manifest = $readJson($manifestPath);

$findings = [];

if (($manifest['schema_version'] ?? null) !== SCHEMA_VERSION) {
    $findings[] = sprintf('schema_version is %s; every released semitexa/update reads only "%s".', $show($manifest['schema_version'] ?? null), SCHEMA_VERSION);
}
if (!is_string($manifest['generated_at'] ?? null) || $manifest['generated_at'] === '') {
    $findings[] = 'generated_at is missing: semitexa/update refuses a manifest without it.';
}

/** @var array<string, list<string>> $known path => every hash known for it (current first) */
$known = [];
/** @var array<string, array<mixed>> $entries path => its entry */
$entries = [];
$files = $manifest['files'] ?? null;
if (!is_array($files) || !array_is_list($files)) {
    $findings[] = 'files is not a list.';
    $files = [];
}
foreach ($files as $index => $entry) {
    if (!is_array($entry)) {
        $findings[] = "files[{$index}] is not an object.";
        continue;
    }
    $path = $entry['path'] ?? null;
    if (!is_string($path) || !$isSafePath($path)) {
        $findings[] = sprintf('files[%d] has a path that is not a safe relative path: %s', $index, $show($path));
        continue;
    }
    if (isset($known[$path])) {
        $findings[] = "{$path}: listed twice.";
        continue;
    }
    $current = $sha256($entry['current_sha256'] ?? null);
    if ($current === null) {
        $findings[] = "{$path}: current_sha256 is not a sha256.";
        continue;
    }
    $history = $entry['previous_sha256'] ?? null;
    if (!is_array($history) || !array_is_list($history)) {
        $findings[] = "{$path}: previous_sha256 is not a list.";
        $history = [];
    }
    $valid = [];
    foreach ($history as $hash) {
        $knownHash = $sha256($hash);
        if ($knownHash === null) {
            $findings[] = sprintf('%s: previous_sha256 holds %s, which is not a sha256.', $path, $show($hash));
        } elseif ($knownHash === $current) {
            $findings[] = "{$path}: previous_sha256 repeats the current hash.";
        } else {
            $valid[] = $knownHash;
        }
    }
    if (count(array_unique($valid)) !== count($valid)) {
        $findings[] = "{$path}: previous_sha256 lists a hash twice.";
    }
    if (!in_array($entry['category'] ?? null, CATEGORIES, true)) {
        $findings[] = sprintf('%s: category %s is not one semitexa/update knows (%s).', $path, $show($entry['category'] ?? null), implode(', ', CATEGORIES));
    }
    foreach (FLAGS as $flag) {
        if (!is_bool($entry[$flag] ?? null)) {
            $findings[] = "{$path}: {$flag} is not true or false.";
        }
    }
    if (!is_string($entry['notes'] ?? null)) {
        $findings[] = "{$path}: notes is not a string.";
    }
    $known[$path] = [$current, ...$valid];
    $entries[$path] = $entry;
}

// Retired: files the scaffold shipped and no longer does. update deletes a
// project's copy that still has a shipped hash, so a path here is read as
// strictly as an entry (ScaffoldManifestLoader::retiredFromArray()).
/** @var array<string, list<string>> $retired path => every hash it shipped with */
$retired = [];
$retiredList = $manifest['retired'] ?? [];
if (!is_array($retiredList) || !array_is_list($retiredList)) {
    $findings[] = 'retired is not a list.';
    $retiredList = [];
}
foreach ($retiredList as $index => $entry) {
    if (!is_array($entry)) {
        $findings[] = "retired[{$index}] is not an object.";
        continue;
    }
    $path = $entry['path'] ?? null;
    if (!is_string($path) || !$isSafePath($path)) {
        $findings[] = sprintf('retired[%d] has a path that is not a safe relative path: %s', $index, $show($path));
        continue;
    }
    if (isset($known[$path])) {
        $findings[] = "{$path}: both shipped and retired.";
        continue;
    }
    $shipped = [];
    foreach (is_array($entry['shipped_sha256'] ?? null) ? $entry['shipped_sha256'] : [] as $hash) {
        $shippedHash = $sha256($hash);
        if ($shippedHash === null) {
            $findings[] = sprintf('%s: retired shipped_sha256 holds %s, which is not a sha256.', $path, $show($hash));
        } else {
            $shipped[] = $shippedHash;
        }
    }
    if ($shipped === []) {
        $findings[] = "{$path}: retired with no shipped_sha256, so no project's copy would be deleted.";
    }
    $retired[$path] = $shipped;
}

// The manifest and scaffold/ name the same files, and each entry names its file as it is now.
$onDisk = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scaffoldRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $onDisk[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($scaffoldRoot) + 1))] = $file->getPathname();
    }
}
ksort($onDisk);
foreach ($onDisk as $path => $absolute) {
    if (!isset($known[$path])) {
        $findings[] = "{$path}: in scaffold/ with no manifest entry, so no project would ever get it.";
        continue;
    }
    $hash = hash_file('sha256', $absolute);
    if ($hash === false) {
        $cannotRead("Cannot read {$absolute}");
    }
    if ($hash !== $known[$path][0]) {
        $findings[] = "{$path}: the file hashes to {$hash}, the manifest says {$known[$path][0]}. Rebuild the manifest after the change.";
    }
}
foreach (array_keys($known) as $path) {
    if (!isset($onDisk[$path])) {
        $findings[] = "{$path}: listed in the manifest with no file in scaffold/.";
    }
}

// Every file has a policy row, and each entry carries it as it is (step 2.5 of
// one source for bin/semitexa: tools/build-manifest.php writes the manifest
// from resources/scaffold-policy.php, so an entry that differs was edited by
// hand or built from another policy).
$policyPath = $root . '/resources/scaffold-policy.php';
$policy = is_file($policyPath) ? require $policyPath : null;
if (!is_array($policy)) {
    $cannotRead("Cannot read the scaffold policy: {$policyPath}");
}
foreach (array_keys($onDisk) as $path) {
    $row = $policy[$path] ?? null;
    if (!is_array($row)) {
        $findings[] = "{$path}: in scaffold/ with no row in resources/scaffold-policy.php, so its category and flags are nobody's decision.";
        continue;
    }
    if (!in_array($row['category'] ?? null, CATEGORIES, true)) {
        $findings[] = sprintf('%s: the policy\'s category %s is not one semitexa/update knows (%s).', $path, $show($row['category'] ?? null), implode(', ', CATEGORIES));
    }
    if (!isset($entries[$path])) {
        continue;
    }
    foreach (['category', ...FLAGS, 'notes'] as $field) {
        if (($entries[$path][$field] ?? null) !== ($row[$field] ?? null)) {
            $findings[] = sprintf('%s: %s is %s, the policy says %s. Rebuild the manifest: php tools/build-manifest.php', $path, $field, $show($entries[$path][$field] ?? null), $show($row[$field] ?? null));
        }
    }
}
foreach (array_keys(array_diff_key($policy, $onDisk)) as $path) {
    $findings[] = "{$path}: a policy row for a file scaffold/ does not have.";
}

// Nothing the earlier manifest knew is forgotten: neither a file it listed nor
// one it retired. A retirement dropped later would leave every project that
// still has a shipped copy of that file with it forever (review, installer#118).
if ($previousPath !== null) {
    $previous = $readJson($previousPath);
    $previousFiles = $previous['files'] ?? null;
    $previousRetired = $previous['retired'] ?? [];
    if (!is_array($previousFiles) || !is_array($previousRetired)) {
        $cannotRead("No files list, or a retired section that is not a list, in {$previousPath}");
    }
    /** @var list<array{string, mixed, string}> $earlier path, the hashes it had, and how the earlier manifest held it */
    $earlier = [];
    foreach ($previousFiles as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            $cannotRead("An entry without a path in {$previousPath}");
        }
        $earlier[] = [$entry['path'], [$entry['current_sha256'] ?? null, ...(is_array($entry['previous_sha256'] ?? null) ? $entry['previous_sha256'] : [])], 'lists'];
    }
    foreach ($previousRetired as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
            $cannotRead("A retired entry without a path in {$previousPath}");
        }
        $earlier[] = [$entry['path'], is_array($entry['shipped_sha256'] ?? null) ? $entry['shipped_sha256'] : [], 'retires'];
    }
    foreach ($earlier as [$path, $hashes, $how]) {
        $nowKnown = $known[$path] ?? $retired[$path] ?? null;
        if ($nowKnown === null) {
            $findings[] = $how === 'lists'
                ? "{$path}: the earlier manifest lists it and this one neither lists nor retires it; a project would keep its copy forever."
                : "{$path}: the earlier manifest retires it and this one neither retires nor lists it; a project's shipped copy would never be removed.";
            continue;
        }
        $was = [];
        foreach (is_array($hashes) ? $hashes : [] as $hash) {
            $earlierHash = $sha256($hash);
            if ($earlierHash !== null) {
                $was[] = $earlierHash;
            }
        }
        foreach (array_diff($was, $nowKnown) as $lost) {
            $findings[] = $how === 'lists'
                ? "{$path}: {$lost} was known to the earlier manifest and is not any more; a project on that version would be told it edited the file."
                : "{$path}: {$lost} was a retired version in the earlier manifest and is not any more; a project's copy at that version would never be removed.";
        }
    }
}

if ($findings !== []) {
    fwrite(STDERR, "resources/scaffold-manifest.json does not hold:\n  - " . implode("\n  - ", $findings) . "\n");
    exit(1);
}

echo sprintf(
    "scaffold-manifest.json describes scaffold/ (%d files)%s.\n",
    count($known),
    $previousPath !== null ? ' and keeps every hash of the earlier manifest' : '',
);
