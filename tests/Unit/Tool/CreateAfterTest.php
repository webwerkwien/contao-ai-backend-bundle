<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\ContentTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ContentUpdateCommand;

/**
 * `content_create` with `after` (v0.22.0, core v1.12.0): the new element goes directly
 * behind an existing one. The voters judge the create below the sibling's parent and
 * parent table, as they would for `pid`; the core gets only `--after` (it refuses
 * `--pid`/`--ptable` alongside). A core without the option is named, not run.
 */
class CreateAfterTest extends TestCase
{
    use BuildsFieldPermission;

    /** @var array<string, mixed>|null */
    private ?array $sent = null;

    /** @var list<array<string, mixed>> */
    private array $createVotes = [];

    protected function setUp(): void
    {
        $this->defineDca(['tl_content' => ['type' => ['inputType' => 'select', 'sql' => ['type' => 'string', 'length' => 64, 'default' => 'text']]]]);
        $this->sent = null;
        $this->createVotes = [];
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @param list<string> $readable "table:id" the voters let the user read
     */
    private function tool(bool $coreHasAfter = true, array $readable = ['tl_content:640'], bool $deniedNews = false): ContentTool
    {
        $options = [new InputOption('type', null, InputOption::VALUE_REQUIRED), new InputOption('pid', null, InputOption::VALUE_REQUIRED), new InputOption('ptable', null, InputOption::VALUE_REQUIRED)];
        if ($coreHasAfter) {
            $options[] = new InputOption('after', null, InputOption::VALUE_REQUIRED);
        }
        $create = $this->createMock(ContentCreateCommand::class);
        $create->method('getDefinition')->willReturn(new InputDefinition($options));
        $create->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output): int {
            $this->sent = array_filter(['type' => $input->getParameterOption('--type'), 'pid' => $input->getParameterOption('--pid'), 'ptable' => $input->getParameterOption('--ptable'), 'after' => $input->getParameterOption('--after')], static fn ($v): bool => false !== $v);
            $output->write('{"status":"ok","id":700}');

            return 0;
        });

        $tool = new ContentTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $create,
            $this->createMock(ContentUpdateCommand::class),
            $this->createMock(ContentDeleteCommand::class),
            $this->createMock(ContentReadCommand::class),
        );

        $rows = [
            'tl_content:640' => ['id' => 640, 'pid' => 8, 'ptable' => 'tl_news', 'type' => 'text', 'sorting' => 640],
            'tl_news:8'      => ['id' => 8, 'pid' => 2],
            'tl_article:35'  => ['id' => 35, 'pid' => 4],
        ];
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): ?array => $rows["$table:$id"] ?? null);
        $records->method('isGranted')->willReturnCallback(static fn (ReadAction $a): bool => \in_array($a->getDataSource() . ':' . $a->getCurrentId(), $readable, true));
        $records->method('assertCreate')->willReturnCallback(function (string $table, array $new): void {
            $this->createVotes[] = $new;
        });
        // an editor may not create below news here: the parent table is the sibling's
        $records->method('contentParentDenial')->willReturnCallback(static fn (array $record): ?string => 'tl_news' === ($record['ptable'] ?? null) && $deniedNews ? 'Keine Rechte auf News.' : null);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) { 'isAdmin' => false, 'username' => 'redakteur', default => null });

        $tool->setFieldPermission($this->fieldPermission(['tl_content::type']));
        $tool->setAuthorizationChecker($this->grantingAuthorization(['tl_content::type']));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    public function testAfterSendsOnlyAfterAndTheVotersSeeTheSiblingsParent(): void
    {
        $this->tool()->create('text', 0, '', 640);

        self::assertSame(['type' => 'text', 'after' => '640'], $this->sent);
        self::assertSame(8, $this->createVotes[0]['pid']);
        self::assertSame('tl_news', $this->createVotes[0]['ptable'], 'the sibling\'s parent table, not the default tl_article');
    }

    /**
     * The editor's parent-table rule judges the sibling's parent table (before v0.22.0
     * a mutation that checked tl_article on the after path passed).
     */
    public function testTheParentTableRuleSeesTheSiblingsParentTable(): void
    {
        try {
            $this->tool(deniedNews: true)->create('text', 0, '', 640);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('News', $e->getMessage());
            self::assertNull($this->sent);
        }
    }

    public function testPtableWithAfterIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->tool()->create('text', 0, 'tl_news', 640);
    }

    public function testPidStillWorksAsBefore(): void
    {
        $this->tool()->create('text', 35);

        self::assertSame(['type' => 'text', 'pid' => '35', 'ptable' => 'tl_article'], $this->sent);
    }

    public function testExactlyOneOfPidAndAfter(): void
    {
        foreach ([[35, 640], [0, 0]] as [$pid, $after]) {
            try {
                $this->tool()->create('text', $pid, 'tl_article', $after);
                self::fail("pid $pid, after $after");
            } catch (ToolRefusedException) {
                self::assertNull($this->sent);
            }
        }
    }

    public function testAnOlderCoreIsNamedInsteadOfRun(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('v1.12.0');

        $this->tool(coreHasAfter: false)->create('text', 0, '', 640);
    }

    public function testASiblingTheUserMayNotReadIsRefused(): void
    {
        try {
            $this->tool(readable: [])->create('text', 0, '', 640);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException) {
            self::assertNull($this->sent);
            self::assertSame([], $this->createVotes);
        }
    }

    public function testAMissingSiblingIsSaid(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('Datensatz 999');

        $this->tool()->create('text', 0, '', 999);
    }
}
