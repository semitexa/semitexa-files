<?php

declare(strict_types=1);

namespace Semitexa\Files\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Files\Application\Handler\PayloadHandler\FilesAppHandler;
use Semitexa\Files\Application\Payload\Request\FilesAppPayload;

/**
 * The Files dialog is one standalone page whose script drives the markup by
 * id. A renamed element or a typo in a lookup leaves a dead screen with no
 * error anywhere, so that coupling is what these tests hold.
 */
final class FilesAppHandlerTest extends TestCase
{
    #[Test]
    public function the_page_is_html_with_the_shell_the_script_starts_from(): void
    {
        $response = $this->render();

        self::assertStringStartsWith('text/html', (string) ($response->getHeaders()['Content-Type'] ?? ''));
        $markup = (string) preg_replace('#<script\b[^>]*>.*?</script>#s', '', (string) $response->getContent());
        foreach (['crumb', 'status', 'main'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $markup, "the shell lacks #{$id}");
        }
    }

    #[Test]
    public function every_element_the_script_looks_up_is_defined_on_the_page(): void
    {
        $html = (string) $this->render()->getContent();
        preg_match_all("/getElementById\\('([A-Za-z0-9_-]+)'\\)/", $html, $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html, "the script looks up #{$id}, which nothing on the page defines");
        }
    }

    private function render(): ResourceResponse
    {
        $resource = (new \ReflectionClass(ResourceResponse::class))->newInstanceWithoutConstructor();

        return (new FilesAppHandler())->handle(new FilesAppPayload(), $resource);
    }
}
