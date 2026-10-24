<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiStreamController;

/**
 * A turn that used tools is stored as a stub, so stale IDs are not reused. The question it
 * ended with stays (v0.19.3): the user's next message answers it. Without it the model met
 * a "Ja, bitte löschen." with no question in sight and refused a delete it had staged
 */
class HistoryKeepsTheQuestionTest extends TestCase
{
    public function testAConfirmationStaysWithTheStub(): void
    {
        $stored = AiStreamController::buildHistoryAssistantContent(
            "Ich habe den Termin gefunden.\n\nDer Termin \"Workshop Fotografie\" (ID 245) soll endgültig gelöscht werden. Wirklich fortfahren?",
            ['record_list', 'event_delete'],
        );

        self::assertStringStartsWith('[Vorheriger Turn: record_list, event_delete aufgerufen.', $stored);
        self::assertStringEndsWith('«Der Termin "Workshop Fotografie" (ID 245) soll endgültig gelöscht werden. Wirklich fortfahren?»', $stored);
        self::assertStringNotContainsString('Ich habe den Termin gefunden', $stored, 'only the closing paragraph');
    }

    public function testAnAnswerWithoutAQuestionKeepsOnlyTheStub(): void
    {
        $stored = AiStreamController::buildHistoryAssistantContent('Erledigt. Die Seite ist veröffentlicht.', ['page_publish']);

        self::assertStringEndsWith('niemals ID raten.]', $stored);
    }

    public function testATurnWithoutToolsIsStoredAsItIs(): void
    {
        self::assertSame('Welche Seite meinen Sie?', AiStreamController::buildHistoryAssistantContent('Welche Seite meinen Sie?', []));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function closings(): iterable
    {
        yield 'markdown bold'   => ["Gefunden.\n**Soll ich ihn löschen?**", 'Soll ich ihn löschen?'];
        yield 'no question'     => ['Erledigt.', null];
        yield 'question inside' => ['Soll ich? Ja, erledigt.', null];
        yield 'long'            => [str_repeat('x', 400) . '?', str_repeat('x', 299) . '?'];
    }

    /**
     * @dataProvider closings
     */
    public function testTheClosingQuestion(string $answer, ?string $question): void
    {
        self::assertSame($question, AiStreamController::closingQuestion($answer));
    }
}
