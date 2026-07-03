<?php

declare(strict_types=1);

namespace Semitexa\Files\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The Files dialog body route (hosted as an iframe in the Focus zone). Accepts
 * an optional `?path=` so it can open at a specific folder (e.g. from a Weave
 * folder node).
 */
#[AsPublicPayload(
    path: '/os/app/files',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class FilesAppPayload implements ValidatablePayloadInterface
{
    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }
}
