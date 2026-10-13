<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Dca;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiCliTokenController;
use Webwerkwien\ContaoAiBackendBundle\Dca\TlUserCallback;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

/**
 * The token widget names the second half of the bridge under the generated token (v0.16.3,
 * showcase.conpai.eu): optional, with this site's address, and without the token itself.
 */
class TlUserCallbackTest extends TestCase
{
    private const TOKEN = '7.s3cr3t-token-value';

    private ?RequestStack $stack = null;

    private function widget(bool $justGenerated, string $url = 'https://showcase.example.org/contao?do=user&act=edit&id=7'): string
    {
        if (null === $this->stack) {
            $session = new Session(new MockArraySessionStorage());
            if ($justGenerated) {
                $session->set(AiCliTokenController::ONE_SHOT_KEY_PREFIX . 7, self::TOKEN);
            }
            $request = Request::create($url);
            $request->setSession($session);
            $this->stack = new RequestStack();
            $this->stack->push($request);
        }
        $stack = $this->stack;

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($justGenerated ? 'hash' : '');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/_ai_cli/token');

        // The translator knows no key: the widget's own (German) fallbacks are used.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key) => 'id' === $key ? 7 : null);

        return (new TlUserCallback($urls, $translator, $connection, $stack, $this->createMock(PlatformRegistry::class)))
            ->tokenWidget($dc);
    }

    public function testAFreshTokenComesWithTheCommandForThisSiteWithoutTheToken(): void
    {
        $html = $this->widget(true);

        $this->assertSame(1, substr_count($html, self::TOKEN), 'the token itself is shown exactly once');
        $this->assertStringContainsString(
            'contao-ai-cli --session &lt;name&gt; bridge configure --url https://showcase.example.org --test',
            $html,
        );
        $this->assertStringContainsString('Optional', $html);
        $this->assertStringContainsString('der Chat braucht den Token nicht', $html);

        // The command does not carry the token: the CLI asks for it with hidden input.
        preg_match_all('/bridge configure[^<]*/', $html, $commands);
        $this->assertNotEmpty($commands[0]);
        foreach ($commands[0] as $command) {
            $this->assertStringNotContainsString(self::TOKEN, $command);
            $this->assertStringNotContainsString('--token', $command);
        }
    }

    public function testTheTokenAndTheCommandAreGoneOnTheNextRender(): void
    {
        $this->widget(true);
        $again = $this->widget(true);

        $this->assertStringNotContainsString(self::TOKEN, $again);
        $this->assertStringNotContainsString('bridge configure', $again);
    }

    /** Review before v0.16.3: behind a TLS proxy without trusted_proxies the request says http. */
    public function testTheCommandAlwaysUsesHttpsTheOnlySchemeTheCliAccepts(): void
    {
        $html = $this->widget(true, 'http://showcase.example.org:8080/contao?do=user&act=edit&id=7');

        $this->assertStringContainsString('--url https://showcase.example.org:8080 --test', $html);
        $this->assertStringNotContainsString('http://showcase', $html);
    }

    public function testWithoutAFreshTokenThereIsNoCommandButTheHelpSaysOptional(): void
    {
        $html = $this->widget(false);

        $this->assertStringNotContainsString('bridge configure', $html);
        $this->assertStringContainsString('Optional: nur für', $html);
    }
}
