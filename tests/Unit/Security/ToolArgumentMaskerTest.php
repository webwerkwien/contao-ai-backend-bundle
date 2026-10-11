<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoAiBackendBundle\Security\ArgumentMaskingLogger;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolArgumentMasker;

/**
 * Secrets in tool arguments stay out of logs (v0.14.1), in every form ext_run takes
 * its options — a map, a list per option, a JSON string (ExtTool::asArray()) — and
 * the forms the Fable review before the release found open.
 */
class ToolArgumentMaskerTest extends TestCase
{
    public function testEveryFormExtRunTakesIsMasked(): void
    {
        $masked = ToolArgumentMasker::mask([
            'command'   => 'acme:shop:connect',
            'arguments' => ['5'],
            'options'   => [
                'set'      => ['password=Geheim1', 'title=Laden'],
                'api-key'  => 'Geheim2',
                // A repeatable option: a list under the secret's name.
                'password' => ['Geheim3', 'Geheim4'],
            ],
        ]);

        $this->assertSame(['password=***', 'title=Laden'], $masked['options']['set']);
        $this->assertSame('***', $masked['options']['api-key']);
        $this->assertSame(['***', '***'], $masked['options']['password']);
        $this->assertSame('acme:shop:connect', $masked['command']);
    }

    public function testOptionsAsAJsonStringAreMaskedByStructure(): void
    {
        $masked = ToolArgumentMasker::mask(['options' => '{"api-key":"Geheim2","token":"Geheim3","set":["password=Geheim1","title=x"]}']);

        $this->assertSame(['api-key' => '***', 'token' => '***', 'set' => ['password=***', 'title=x']], json_decode($masked['options'], true));
    }

    public function testNamesWithASeparatorCountToo(): void
    {
        $text = ToolArgumentMasker::maskText('--set pass_phrase=Geheim1 --pass-phrase Geheim2 --smtp-pass Geheim3 --set passive=1');

        $this->assertSame('--set pass_phrase=*** --pass-phrase *** --smtp-pass *** --set passive=1', $text);
    }

    public function testALongQuotedValueDoesNotLetTheSecretThrough(): void
    {
        // The first version's pattern failed past 8 KB in quotes and handed back ''.
        $long = str_repeat('Lorem ipsum ', 2000);
        $text = ToolArgumentMasker::maskText('--set token="' . $long . '" --set password=Geheim1');

        $this->assertStringNotContainsString('Geheim1', $text);
        $this->assertNotSame('', $text);
    }

    public function testTheToolboxLoggerGetsMaskedContext(): void
    {
        $inner = new class extends AbstractLogger {
            /** @var array<mixed> */
            public array $context = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->context = $context;
            }
        };

        // symfony/ai's Toolbox: `Executing tool "…"` with the raw arguments as context.
        (new ArgumentMaskingLogger($inner))->debug('Executing tool "ext_run".', ['arguments' => ['options' => ['api-key' => 'Geheim2']]]);

        $this->assertSame('***', $inner->context['arguments']['options']['api-key']);
    }
}
