<?php

declare(strict_types=1);

namespace Semitexa\Files\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Files\Application\Handler\PayloadHandler\FilesAppHandler;
use Semitexa\Files\Application\Payload\Request\FilesAppPayload;
use Semitexa\Files\Application\Service\FilesSkill;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

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

    // verify:accept-test-change the script moved to Static/js/files-app.js; its lookups are read there, the page must link it
    #[Test]
    public function every_element_the_script_looks_up_is_defined_on_the_page(): void
    {
        $page = (string) $this->render()->getContent();
        self::assertMatchesRegularExpression('#<script src="/assets/files/js/files-app\\.js\\?v=[^"]+"></script>#', $page);
        // The script also writes markup of its own, so an id it creates counts as defined.
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Static/js/files-app.js');
        $html = $page . $script;
        preg_match_all("/getElementById\\('([A-Za-z0-9_-]+)'\\)/", $script, $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html, "the script looks up #{$id}, which nothing on the page defines");
        }
    }

    /**
     * Since July every dark token named itself (--text:var(--text)): a cycle
     * is invalid, so text, accent and folder colours silently fell away in
     * the dark theme.
     */
    #[Test]
    public function every_theme_token_has_a_value_in_both_palettes(): void
    {
        $html = (string) $this->render()->getContent();
        self::assertSame(1, preg_match('/:root\{color-scheme:dark;([^}]*)\}/', $html, $dark));
        self::assertSame(1, preg_match('/:root\[data-mode=light\]\{color-scheme:light;([^}]*)\}/', $html, $light));

        $tokens = static function (string $block): array {
            preg_match_all('/(--[a-z0-9-]+):([^;]+)/', $block, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as [, $name, $value]) {
                $out[$name] = trim($value);
            }

            return $out;
        };
        $darkTokens = $tokens($dark[1]);
        $lightTokens = $tokens($light[1]);

        // Every token the page reads must be set by each palette: equal key
        // lists alone would still pass if a token vanished from both.
        preg_match_all('/var\((--[a-z0-9-]+)\)/', $html, $used);
        $required = array_values(array_unique($used[1]));
        sort($required);
        self::assertSame(['--accent', '--accent-rgb', '--bg', '--dim', '--folder', '--line-rgb', '--mute', '--text', '--text-2', '--text-3'], $required);

        // Each palette is checked on its own: a union would let the dark value
        // stand in for a broken light one of the same name.
        foreach (['dark' => $darkTokens, 'light' => $lightTokens] as $palette => $set) {
            $names = array_keys($set);
            sort($names);
            self::assertSame($required, $names, "the {$palette} palette sets exactly the tokens the page uses");
            // Each in the form its use needs: color:var(--text) takes a hex
            // colour, rgba(var(--accent-rgb),.4) takes bare channels. Either
            // form under the other name would leave the rule invalid.
            foreach ($set as $name => $value) {
                $format = str_ends_with($name, '-rgb') ? '/^\d{1,3},\d{1,3},\d{1,3}$/' : '/^#[0-9a-f]{6}$/';
                self::assertMatchesRegularExpression($format, $value, "{$palette} {$name} is a colour in the form its use needs");
            }
        }
    }

    #[Test]
    public function the_window_is_a_console_surface_the_files_skill_opens(): void
    {
        $route = (new \ReflectionClass(FilesAppPayload::class))->getAttributes(AsProtectedPayload::class)[0]->newInstance();
        $skill = (new \ReflectionClass(FilesSkill::class))->getAttributes(AsAiSkill::class)[0]->newInstance();

        self::assertInstanceOf(OsSurfacePayloadInterface::class, new FilesAppPayload(), 'gated as an OS console window, not merely signed in');
        self::assertSame('/os/app/files', $route->path);
        self::assertSame($route->path, $skill->entry, 'the skill opens the window it names');
        self::assertSame([], (new FilesAppPayload())->validate());
    }

    private function render(): ResourceResponse
    {
        $resource = (new \ReflectionClass(ResourceResponse::class))->newInstanceWithoutConstructor();

        return (new FilesAppHandler())->handle(new FilesAppPayload(), $resource);
    }
}
