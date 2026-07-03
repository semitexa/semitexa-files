<?php

declare(strict_types=1);

namespace Semitexa\Files\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Semitexa Files UI-skill: an in-OS file manager. Its dialog body ({@see
 * \Semitexa\Files\Application\Handler\PayloadHandler\FilesAppHandler}, entry
 * `/os/app/files`) browses REAL files/folders through the local bridge, so the
 * OS gets a native file view (open a folder → land here) without the graph or
 * OS internals knowing anything about the filesystem — this package owns it.
 */
#[AsAiSkill(
    name: 'Files',
    summary: 'Open the file manager to browse and open your real files and folders.',
    useWhen: 'The user wants to browse files or folders, open a project directory, or look through their files ("open files", "show my files", "browse the project folder", "file manager").',
    avoidWhen: 'The user wants to open a specific app/site, restyle, or manage tasks — not browse the filesystem.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'folder-open',
    entry: '/os/app/files',
)]
final class FilesSkill
{
}
