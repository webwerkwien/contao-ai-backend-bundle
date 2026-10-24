<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ArticleTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\PageTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;

/**
 * `article_create` and `page_create` with `after` (v0.22.0; there was no test, and a
 * mutation that voted on the article's ID as the page passed). The voters judge the
 * sibling's page or parent — and for an article the column the core will write: the
 * sibling's, unless one is given (`main` was always sent and overrode it).
 */
class CreateAfterArticlePageTest extends TestCase
{
    use BuildsFieldPermission;

    /** @var array<string, string|false> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $votes = [];

    private const ROWS = [
        'tl_article:12' => ['id' => 12, 'pid' => 4, 'inColumn' => 'right', 'title' => 'Seitenleiste'],
        'tl_page:4'     => ['id' => 4, 'pid' => 1, 'type' => 'regular'],
        'tl_page:21'    => ['id' => 21, 'pid' => 3, 'type' => 'regular'],
        'tl_page:3'     => ['id' => 3, 'pid' => 1, 'type' => 'regular'],
        // a page whose ID is the article's: a vote on the wrong table would find it
        'tl_page:12'    => ['id' => 12, 'pid' => 9, 'type' => 'regular'],
    ];

    protected function setUp(): void
    {
        $this->defineDca([
            'tl_article' => ['title' => ['inputType' => 'text', 'sql' => 'varchar(255)'], 'inColumn' => ['inputType' => 'select', 'sql' => 'varchar(32)']],
            'tl_page'    => ['title' => ['inputType' => 'text', 'sql' => 'varchar(255)'], 'alias' => ['inputType' => 'text', 'sql' => 'varchar(255)'], 'type' => ['inputType' => 'select', 'sql' => 'varchar(64)']],
        ]);
        $this->sent = [];
        $this->votes = [];
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     * @param list<string>    $options
     *
     * @return T&\PHPUnit\Framework\MockObject\MockObject
     */
    private function command(string $class, array $options): Command
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn(new InputDefinition(array_map(static fn (string $o): InputOption => new InputOption($o, null, InputOption::VALUE_REQUIRED), $options)));
        $command->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output) use ($options): int {
            foreach ($options as $option) {
                $this->sent[$option] = $input->hasParameterOption('--' . $option) ? $input->getParameterOption('--' . $option) : false;
            }
            $output->write('{"status":"ok","id":99}');

            return 0;
        });

        return $command;
    }

    /**
     * @param list<string> $granted
     */
    private function wire(AbstractCoreCommandTool $tool, array $granted): AbstractCoreCommandTool
    {
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): ?array => self::ROWS["$table:$id"] ?? null);
        $records->method('isGranted')->willReturnCallback(static fn (ReadAction $a): bool => true);
        $records->method('assertCreate')->willReturnCallback(function (string $table, array $new): void {
            $this->votes[] = $new;
        });

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) { 'isAdmin' => false, 'username' => 'redakteur', default => null });

        $tool->setFieldPermission($this->fieldPermission($granted));
        $tool->setAuthorizationChecker($this->grantingAuthorization($granted));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    private function articleTool(): ArticleTool
    {
        $tool = new ArticleTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(ArticleCreateCommand::class, ['title', 'pid', 'inColumn', 'after']),
            $this->createMock(ArticleUpdateCommand::class),
            $this->createMock(ArticleDeleteCommand::class),
            $this->createMock(ArticleReadCommand::class),
        );
        $this->wire($tool, ['tl_article::title', 'tl_article::inColumn']);

        return $tool;
    }

    private function pageTool(): PageTool
    {
        $tool = new PageTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(PageCreateCommand::class, ['title', 'alias', 'type', 'pid', 'after']),
            $this->createMock(PageUpdateCommand::class),
            $this->createMock(PageDeleteCommand::class),
            $this->createMock(PageReadCommand::class),
            $this->createMock(PagePublishCommand::class),
        );
        $this->wire($tool, ['tl_page::title', 'tl_page::alias', 'tl_page::type']);

        return $tool;
    }

    public function testAnArticleAfterAnotherTakesItsPageAndColumn(): void
    {
        $this->articleTool()->create('Zweite Leiste', 0, '', 12);

        self::assertSame(['title' => 'Zweite Leiste', 'pid' => false, 'inColumn' => false, 'after' => '12'], $this->sent, 'neither --pid nor --inColumn: the core takes both from the sibling');
        self::assertSame([4, 'right'], [$this->votes[0]['pid'], $this->votes[0]['inColumn']], 'voted on the sibling\'s page (4, not the page with the article\'s ID) and column');
    }

    public function testAColumnGivenWithAfterIsSentAndVotedOn(): void
    {
        $this->articleTool()->create('Haupt', 0, 'main', 12);

        self::assertSame('main', $this->sent['inColumn']);
        self::assertSame('main', $this->votes[0]['inColumn']);
    }

    public function testAnArticleWithPidStaysInMain(): void
    {
        $this->articleTool()->create('Neu', 4);

        self::assertSame(['title' => 'Neu', 'pid' => '4', 'inColumn' => 'main', 'after' => false], $this->sent);
    }

    public function testAPageAfterAnotherTakesItsParent(): void
    {
        $this->pageTool()->create('Zwischen', '', 'regular', 0, 21);

        self::assertSame(['title' => 'Zwischen', 'alias' => '', 'type' => 'regular', 'pid' => false, 'after' => '21'], $this->sent);
        self::assertSame(3, $this->votes[0]['pid']);
    }

    public function testAPageWithBothOrNeitherIsRefusedUnlessARoot(): void
    {
        foreach ([[3, 21, 'regular'], [0, 0, 'regular']] as [$pid, $after, $type]) {
            try {
                $this->pageTool()->create('X', '', $type, $pid, $after);
                self::fail("pid $pid, after $after");
            } catch (ToolRefusedException) {
                self::assertSame([], $this->sent);
            }
        }

        $this->pageTool()->create('Startpunkt', '', 'root');
        self::assertSame(['title' => 'Startpunkt', 'alias' => '', 'type' => 'root', 'pid' => '0', 'after' => false], $this->sent);
    }
}
