<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ArticleTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ArticleUpdateCommand;

/**
 * Taking a live article offline asks first, as unpublishing a page has since 2026-09-02
 * (decided 2026-10-19, v0.19.3). The real ArticleTool, the real FieldPermission; the
 * pending store and the command are recorded.
 */
class ArticleUnpublishAsksTest extends TestCase
{
    use BuildsFieldPermission;

    private const FLAG = ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]];

    private bool $ran = false;

    /** @var list<string> */
    private array $staged = [];

    protected function setUp(): void
    {
        $this->defineDca(['tl_article' => [
            'title'     => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            'published' => self::FLAG,
        ]]);
        $this->ran = false;
        $this->staged = [];
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function offline(): iterable
    {
        yield 'empty string' => [''];
        yield 'zero string'  => ['0'];
        yield 'false'        => [false];
        yield 'zero'         => [0];
        yield 'false as text' => ['false'];
    }

    /**
     * @dataProvider offline
     */
    public function testTakingALiveArticleOfflineAsksAndWritesNothing(mixed $value): void
    {
        $answer = $this->send($this->tool(livePublished: true), ['published' => $value]);

        $this->assertStringContainsString('pending_confirmation', $answer);
        $this->assertSame(['article_update:unpublish:7'], $this->staged);
        $this->assertFalse($this->ran);
    }

    public function testAfterTheYesItRuns(): void
    {
        $this->send($this->tool(livePublished: true, confirmed: true), ['published', '']);

        $this->assertTrue($this->ran);
    }

    public function testPublishingRunsWithoutAQuestion(): void
    {
        $this->send($this->tool(livePublished: false), ['published' => '1']);

        $this->assertSame([], $this->staged);
        $this->assertTrue($this->ran);
    }

    public function testAnArticleAlreadyOfflineIsNotAskedAbout(): void
    {
        $this->send($this->tool(livePublished: false), ['published' => '']);

        $this->assertSame([], $this->staged);
        $this->assertTrue($this->ran);
    }

    public function testOtherFieldsRunWithoutAQuestion(): void
    {
        $this->send($this->tool(livePublished: true), ['title' => 'Neu']);

        $this->assertSame([], $this->staged);
        $this->assertTrue($this->ran);
    }

    /**
     * The field right is checked before the question: nobody confirms what is refused.
     */
    public function testWithoutTheRightThereIsNoQuestion(): void
    {
        $tool = $this->tool(livePublished: true, granted: ['tl_article::title']);

        try {
            $this->send($tool, ['published' => '']);
            $this->fail('expected a refusal');
        } catch (ToolAccessDeniedException $e) {
            $this->assertStringContainsString('tl_article.published', $e->getMessage());
        }

        $this->assertSame([], $this->staged);
        $this->assertFalse($this->ran);
    }

    /**
     * @param array<int|string, mixed> $fields
     */
    private function send(AbstractCoreCommandTool $tool, array $fields): string
    {
        $this->assertTrue(method_exists($tool, 'update'));

        return (string) $tool->update(7, $fields);
    }

    /**
     * @param list<string> $granted
     */
    private function tool(bool $livePublished, bool $confirmed = false, array $granted = ['tl_article::title', 'tl_article::published']): AbstractCoreCommandTool
    {
        $update = $this->createMock(ArticleUpdateCommand::class);
        $update->method('getDefinition')->willReturn(new InputDefinition());
        $update->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output): int {
            $this->ran = true;
            $output->write('{"status":"ok","id":7}');

            return 0;
        });

        $tool = new ArticleTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ArticleCreateCommand::class),
            $update,
            $this->createMock(ArticleDeleteCommand::class),
            $this->createMock(ArticleReadCommand::class),
        );

        $row = ['id' => 7, 'pid' => 3, 'title' => 'Angebot', 'published' => $livePublished ? 1 : 0];
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('assertUpdate')->willReturn($row);

        $store = $this->createMock(PendingActionStore::class);
        $store->method('stagedInCurrentTurn')->willReturn(false);
        $store->method('consume')->willReturn($confirmed ? ['id' => 7] : null);
        $store->method('stage')->willReturnCallback(function (int $userId, string $tool, string $key): void {
            $this->staged[] = $tool . ':' . $key;
        });

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'id'       => 12,
            'username' => 'redakteur',
            default    => null,
        });
        // `$user->id ?? 0` asks __isset first
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'id', 'username'], true));

        $tool->setFieldPermission($this->fieldPermission($granted));
        $tool->setAuthorizationChecker($this->grantingAuthorization($granted));
        $tool->setPendingActionStore($store);
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }
}
