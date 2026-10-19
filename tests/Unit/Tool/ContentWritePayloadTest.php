<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;

/**
 * What `content_update` hands the core for the shapes a model sends (v0.19.2).
 *
 * symfony/ai describes `array $fields` as a list of scalars, so a headline's level
 * arrived as a field of its own and was refused: `Feld "unit" ist für dieses Tool nicht
 * zum Schreiben freigegeben` (showcase, 2026-10-19). And a content element could not be
 * hidden at all, `invisible` was not on the list.
 *
 * The real tool with the real FieldPermission; the command records its `--set`.
 */
class ContentWritePayloadTest extends TestCase
{
    use BuildsFieldPermission;

    /** @var list<string>|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        $this->defineDca([
            'tl_content' => [
                'headline'  => ['inputType' => 'inputUnit', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'sql' => 'varchar(255) NOT NULL default \'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}\''],
                // Contao's second inputUnit field on tl_content, not on the tool's list
                'sectionHeadline' => ['inputType' => 'inputUnit', 'options' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'sql' => 'varchar(255) NOT NULL default \'a:2:{s:5:"value";s:0:"";s:4:"unit";s:2:"h2";}\''],
                'text'      => ['inputType' => 'textarea', 'sql' => 'text NULL'],
                'html'      => ['inputType' => 'textarea', 'sql' => 'text NULL'],
                'invisible' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
                'type'      => ['inputType' => 'select', 'sql' => ['type' => 'string', 'length' => 64, 'default' => 'text']],
            ],
        ]);
        $this->sent = null;
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>, list<string>|null}>
     */
    public static function shapes(): iterable
    {
        yield 'alternating list with the level'       => [['headline', 'Titel', 'unit', 'h2'], ['headline=Titel', 'headline_unit=h2']];
        yield 'field=value strings with the level'    => [['headline=Titel', 'unit=h3'], ['headline=Titel', 'headline_unit=h3']];
        yield 'only the level'                        => [['unit', 'h4'], ['headline_unit=h4']];
        yield 'the headline object as the payload'    => [['unit' => 'h2', 'value' => 'Titel'], ['headline_unit=h2', 'headline=Titel']];
        yield 'the headline as an object'             => [['headline' => ['unit' => 'h2', 'value' => 'Titel']], ['headline={"value":"Titel","unit":"h2"}']];
        yield 'a list holding the headline object'    => [[['headline' => ['unit' => 'h1', 'value' => 'Ä']]], ['headline={"value":"Ä","unit":"h1"}']];
        yield 'the headline as a JSON string'         => [['headline', '{"unit":"h2","value":"Titel"}'], ['headline={"unit":"h2","value":"Titel"}']];
        yield 'the companion key the core knows'      => [['headline_unit' => 'h5'], ['headline_unit=h5']];
        yield 'an explicit companion beats a bare unit' => [['headline' => 'Titel', 'headline_unit' => 'h1', 'unit' => 'h2'], null];
        yield 'hiding an element'                     => [['invisible', '1'], ['invisible=1']];
        yield 'showing it again'                      => [['invisible' => ''], ['invisible=']];
    }

    /**
     * @dataProvider shapes
     *
     * @param array<int|string, mixed> $fields
     * @param list<string>|null        $expected null: refused, nothing run
     */
    public function testWhatTheCoreReceives(array $fields, ?array $expected): void
    {
        $tool = $this->tool(['tl_content::headline', 'tl_content::invisible']);

        if (null === $expected) {
            // both name the level: not guessed which one wins, `unit` stays a field of its own
            $this->expectException(ToolAccessDeniedException::class);
            $this->expectExceptionMessage('Feld "unit"');
        }

        $this->send($tool, $fields);

        $this->assertSame($expected, $this->sent);
    }

    /**
     * The level of a field the tool does not offer stays refused, even though the DCA
     * gives that field a unit: only the tool's own inputUnit fields take a companion key.
     */
    public function testTheLevelOfAFieldNotOnTheListIsRefused(): void
    {
        $tool = $this->tool(['tl_content::headline', 'tl_content::sectionHeadline']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('Feld "sectionHeadline_unit"');

        $this->send($tool, ['sectionHeadline_unit' => 'h3']);
    }

    /**
     * The voters are asked with what the core writes, the folded level included — not
     * with the model's raw `unit` (Fable review 2026-10-19: removing the call before
     * writtenValues() passed every other test).
     */
    public function testTheVotersSeeTheFoldedValues(): void
    {
        $voted = null;
        $tool = $this->tool(['tl_content::headline'], static function (string $table, int $id, ?array $new) use (&$voted): void {
            $voted = $new;
        });

        $this->send($tool, ['headline', 'Titel', 'unit', 'h2']);

        $this->assertSame(['headline' => 'Titel', 'headline_unit' => 'h2'], $voted);
    }

    public function testTheLevelNeedsTheRightOnTheHeadline(): void
    {
        $tool = $this->tool(['tl_content::invisible']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_content.headline');

        $this->send($tool, ['unit', 'h2']);
    }

    public function testHidingNeedsTheRightOnInvisible(): void
    {
        $tool = $this->tool(['tl_content::headline']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_content.invisible');

        $this->send($tool, ['invisible' => '1']);
    }

    /**
     * Until v0.19.1 an object for any field reached the command as the text "Array".
     */
    public function testAnObjectForAFieldWithoutAUnitIsRefused(): void
    {
        $tool = $this->tool(['tl_content::headline', 'tl_content::text']);

        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('Feld "text" erwartet einen einfachen Wert');

        $this->send($tool, ['text' => ['value' => 'x']]);
    }

    public function testAHeadlineObjectWithOtherKeysIsRefused(): void
    {
        $tool = $this->tool(['tl_content::headline']);

        $this->expectException(ToolRefusedException::class);

        $this->send($tool, ['headline' => ['value' => 'x', 'unit' => 'h2', 'class' => 'big']]);
    }

    /**
     * Two fields with a unit and the payload sets neither: which one the level belongs
     * to is not guessed, `unit` is refused with the way to name it.
     */
    public function testAUnitWithTwoCandidatesIsNotGuessed(): void
    {
        $this->defineDca(['tl_content' => array_replace($GLOBALS['TL_DCA']['tl_content']['fields'], ['text' => ['inputType' => 'inputUnit', 'sql' => 'text NULL']])]);
        $tool = $this->tool(['tl_content::headline', 'tl_content::text']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('Feld "unit" ist für dieses Tool nicht zum Schreiben freigegeben. Die Ebene einer Überschrift');

        $this->send($tool, ['unit' => 'h2']);
    }

    /**
     * The shapes a model sends are not what the docblock declares (that is the schema it
     * is shown), so the call goes through the base type, as in FieldRightsWiringTest.
     *
     * @param array<int|string, mixed> $fields
     */
    private function send(AbstractCoreCommandTool $tool, array $fields): void
    {
        $this->assertTrue(method_exists($tool, 'update'));
        $tool->update(5, $fields);
    }

    /**
     * @param list<string>                                           $granted
     * @param (callable(string, int, array<string, mixed>|null): void)|null $onUpdateVote sees what the voters are asked with
     */
    private function tool(array $granted, ?callable $onUpdateVote = null): AbstractCoreCommandTool
    {
        $update = $this->createMock(ContentUpdateCommand::class);
        $update->method('getDefinition')->willReturn(new InputDefinition());
        $update->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output): int {
            $this->sent = array_values((array) $input->getParameterOption('--set'));
            $output->write('{"status":"ok","id":5}');

            return 0;
        });

        $tool = new ContentTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContentCreateCommand::class),
            $update,
            $this->createMock(ContentDeleteCommand::class),
            $this->createMock(ContentReadCommand::class),
        );

        $row = ['id' => 5, 'pid' => 3, 'ptable' => 'tl_article', 'type' => 'text'];
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('assertUpdate')->willReturnCallback(static function (string $table, int $id, ?array $new = null) use ($row, $onUpdateVote): array {
            if (null !== $onUpdateVote) {
                $onUpdateVote($table, $id, $new);
            }

            return $row;
        });
        $records->method('fetchRecord')->willReturn($row);
        $records->method('contentParentDenial')->willReturn(null);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'username' => 'redakteur',
            default    => null,
        });

        $tool->setFieldPermission($this->fieldPermission($granted));
        $tool->setAuthorizationChecker($this->grantingAuthorization($granted));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }
}
