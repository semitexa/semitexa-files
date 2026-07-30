<?php

declare(strict_types=1);

namespace Semitexa\Files;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'files.manager',
    summary: 'An in-OS file manager that browses real files and folders through the local bridge.',
    useWhen: 'Users need to browse and manipulate actual files and folders from inside the application.',
    avoidWhen: 'You only need upload and download of user content - semitexa/storage and semitexa/media cover that without a local bridge.',
    replaces: [
        'a bespoke file-browser UI plus custom endpoints for listing and reading directories',
        'shelling out to the filesystem from a handler and rendering the result by hand',
    ],
)]
final class Capabilities
{
}
