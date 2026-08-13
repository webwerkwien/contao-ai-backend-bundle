<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Service\Rewriter;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\RefusalDetectionTrait;

/**
 * Guards the pattern that keeps LLM clarification replies out of editorial
 * fields. It lives in one place now because it had drifted — the Phase-10.4
 * extension reached News/Event/Faq but not Content/Page/Article.
 */
class RefusalDetectionTraitTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        $this->subject = new class {
            use RefusalDetectionTrait;

            public function isRefusal(string $rewritten, string $original): bool
            {
                return $this->looksLikeRefusal($rewritten, $original);
            }
        };
    }

    /**
     * The reply that actually reached tl_news on 2026-05-08 and sat in the
     * database until 2026-08-13.
     */
    public function testRealWorldAnthropicRefusalIsCaught(): void
    {
        $this->assertTrue($this->subject->isRefusal(
            "I'm ready to transform editorial text according to your instructions. "
            . 'Please provide the headline you would like me to translate from German to English.',
            'Contao ist beliebt'
        ));
    }

    /**
     * @dataProvider refusalOpenings
     */
    public function testRefusalOpeningsAreCaught(string $reply): void
    {
        $this->assertTrue($this->subject->isRefusal($reply, 'Kurz'));
    }

    public static function refusalOpenings(): array
    {
        return [
            'OpenAI style'      => ['I need more context to transform this text properly.'],
            'please provide'    => ['Please provide the text you want me to rewrite for you.'],
            'happy to'          => ['Happy to help! Just send me the headline you want translated.'],
            'ready to'          => ['Ready to transform the copy as soon as you share it with me.'],
            'sure comma'        => ['Sure, I can do that once you tell me which text you mean.'],
            'of course'         => ['Of course! Please share the content you need rewritten here.'],
            'could you'         => ['Could you clarify which part of the text should be rewritten?'],
            'german brauche'    => ['Ich brauche noch den Text, den ich umschreiben soll, bitte.'],
            'german bitte'      => ['Bitte senden Sie mir den Text, den ich übersetzen soll, zu.'],
            'it seems like'     => ['It seems like the input is empty, so there is nothing to do.'],
        ];
    }

    /**
     * The length factor is what keeps genuine rewrites alive: a real
     * transformation stays near the source length, a clarification balloons.
     */
    public function testGenuineRewriteStartingWithIIsKept(): void
    {
        $this->assertFalse($this->subject->isRefusal(
            'I love Contao',
            'Ich liebe Contao'
        ));
    }

    public function testLongGenuineTranslationIsKept(): void
    {
        $this->assertFalse($this->subject->isRefusal(
            'Contao is popular among agencies because it combines editorial comfort with control.',
            'Contao erfreut sich bei Agenturen großer Beliebtheit, weil es Redaktionskomfort mit Kontrolle verbindet.'
        ));
    }

    /**
     * A refusal-looking opening that is NOT longer than the source stays —
     * otherwise short legitimate results would be dropped.
     */
    public function testShortReplyBelowLengthFactorIsKept(): void
    {
        $this->assertFalse($this->subject->isRefusal(
            'I need you',
            'I need you to know that this is a long original text about something'
        ));
    }
}
