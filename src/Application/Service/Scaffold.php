<?php

declare(strict_types=1);

namespace Semitexa\Installer\Application\Service;

/**
 * Where this package keeps the project scaffold.
 *
 * The scaffold is the set of files a Semitexa project gets from the framework
 * and `bin/semitexa update` keeps current: bin/semitexa, the Dockerfile, the
 * Compose files, .env.default. A reader asks here for the paths, so it works
 * the same from vendor/ and from a workspace checkout and never spells a
 * vendor path itself.
 */
final class Scaffold
{
    /** The directory holding the scaffold files, at their project-relative paths. */
    public static function directory(): string
    {
        return self::packageRoot() . '/scaffold';
    }

    /**
     * The manifest of the files under directory(): for each, the sha256 it has
     * now and every sha256 it had in an earlier release.
     */
    public static function manifestPath(): string
    {
        return self::packageRoot() . '/resources/scaffold-manifest.json';
    }

    private static function packageRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
