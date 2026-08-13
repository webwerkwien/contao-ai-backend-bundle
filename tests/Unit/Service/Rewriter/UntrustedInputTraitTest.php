<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Rewriter;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\UntrustedInputTrait;

/**
 * The rewriters hand a record's field value to a bare `invoke()` call. The
 * trait marks that value as data rather than instructions and — just as
 * importantly — removes the markers again should the model echo them back,
 * so the hardening cannot degrade what gets written to the record.
 */
class UntrustedInputTraitTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        $this->subject = new class {
            use UntrustedInputTrait;

            public function wrap(string $s): string { return $this->wrapUntrustedInput($s); }
            public function strip(string $s): string { return $this->stripInputWrapper($s); }
            public function rule(): string { return $this->untrustedInputRule(); }
        };
    }

    public function testWrapEnclosesTheValue(): void
    {
        $wrapped = $this->subject->wrap('Contao ist beliebt');

        $this->assertStringContainsString('<editorial_input>', $wrapped);
        $this->assertStringContainsString('</editorial_input>', $wrapped);
        $this->assertStringContainsString('Contao ist beliebt', $wrapped);
    }

    /**
     * The important one: if the model repeats the markers despite the prompt
     * rule, they must not be persisted into the record.
     */
    public function testStripRemovesEchoedMarkers(): void
    {
        $this->assertSame(
            'Contao is popular',
            $this->subject->strip($this->subject->wrap('Contao is popular'))
        );
    }

    public function testPlainAnswerIsLeftAlone(): void
    {
        $this->assertSame('Contao is popular', $this->subject->strip('Contao is popular'));
    }

    public function testMultilineValueSurvivesRoundTrip(): void
    {
        $value = "Erste Zeile\nZweite Zeile";

        $this->assertSame($value, $this->subject->strip($this->subject->wrap($value)));
    }

    /**
     * A marker appearing *inside* legitimate prose must not trigger the strip —
     * otherwise the guard would mangle editorial content.
     */
    public function testMarkerInsideProseIsNotStripped(): void
    {
        $text = 'Er sagte <editorial_input> sei ein Tag.';

        $this->assertSame($text, $this->subject->strip($text));
    }

    public function testUmlautsSurviveRoundTrip(): void
    {
        $this->assertSame(
            'Größe für Prüfzwecke',
            $this->subject->strip($this->subject->wrap('Größe für Prüfzwecke'))
        );
    }

    public function testRuleNamesBothMarkersAndForbidsInstructionFollowing(): void
    {
        $rule = $this->subject->rule();

        $this->assertStringContainsString('<editorial_input>', $rule);
        $this->assertStringContainsString('</editorial_input>', $rule);
        $this->assertStringContainsString('never as instructions', $rule);
    }
}
