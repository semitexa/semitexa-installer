<?php

declare(strict_types=1);

namespace Semitexa\Installer;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * It sits at src/Capabilities.php like every package's, so the set of packages
 * carrying one is the whole set, with no exception to remember; the capability
 * catalog requires it by that path from the workspace checkout.
 *
 * Nothing reads this at runtime, so the package requires nothing for it: the
 * attribute's class is semitexa/core's, and is never loaded from here.
 */
#[Capability(
    id: 'dist.installer',
    summary: 'The project scaffold and the image that writes it: bin/semitexa, the Dockerfile, the Compose files and .env.default a Semitexa project runs on, each with the hashes it ever shipped with.',
    useWhen: 'Starting a project (the semitexa/installer image writes the scaffold into an empty directory) or keeping one current: bin/semitexa update replaces the scaffold files a project has not edited.',
    avoidWhen: 'Changing how one project runs - edit that project\'s own copy, or its docker-compose.override.yml; an edited scaffold file is kept, and its new version arrives beside it as .new.',
    replaces: [
        'writing a Dockerfile, the Compose files and a CLI wrapper by hand for each project',
        'copying those files from the previous project and finding out later which of them were stale',
    ],
)]
final class Capabilities
{
}
