<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\EntityRewriterInterface;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordListTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordRewriteTool;
use Webwerkwien\ContaoAiCoreBundle\Command\NewsUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordListCommand;

/**
 * The macro tools and the list reach the record rights (v0.18.0) through the real tool
 * (rule 24). The Fable review of v0.18.0 deleted each of these four calls and the suite
 * stayed green: the clone's create check, the subpages of a recursive clone, the
 * children of a rewrite, and the row filter of record_list.
 */
class MacroRecordRightsWiringTest extends TestCase
{
    private function editor(): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'id'       => 3,
            'username' => 'redakteur',
            'modules'  => ['news', 'page'],
            default    => null,
        });
        // `$user->modules ?? []` asks __isset first.
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'id', 'username', 'modules'], true));

        return $user;
    }

    /**
     * @return \ArrayObject<int, int>
     */
    private static function ids(): \ArrayObject
    {
        return new \ArrayObject();
    }

    private function wire(AbstractCoreCommandTool $tool, ?DataContainerAccess $records = null): void
    {
        $tool->setFieldPermission($this->createMock(FieldPermission::class));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records ?? $this->createMock(DataContainerAccess::class));
        $tool->setActingUserOverride($this->editor());
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class, int $runs, string $answer = '{"status":"ok","id":9,"table":"tl_news_archive"}'): Command
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn(new InputDefinition());
        $command->expects($this->exactly($runs))->method('run')->willReturnCallback(
            static function (InputInterface $input, OutputInterface $output) use ($answer): int {
                $output->write($answer);

                return 0;
            },
        );

        return $command;
    }

    private function cloneTool(RecordPermissionChecker $checker, int $runs, ?Connection $connection = null): RecordCloneTool
    {
        $tool = new RecordCloneTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(RecordCloneCommand::class, $runs),
            $checker,
            $this->createMock(ContaoFramework::class),
            $connection ?? $this->createMock(Connection::class),
        );
        $this->wire($tool);

        return $tool;
    }

    public function testACloneTheUserMayNotCreateRunsNothing(): void
    {
        $checker = $this->createMock(RecordPermissionChecker::class);
        $checker->expects($this->once())->method('assertCanCreateContainer')
            ->with($this->anything(), 'tl_news_archive', 4)
            ->willThrowException(new ToolAccessDeniedException('kein Anlage-Recht'));

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('kein Anlage-Recht');

        $this->cloneTool($checker, 0)->cloneRecord('tl_news_archive', 4);
    }

    /**
     * A page, because after an archive clone the tool adds the new archive to the user's
     * allow-list through UserModel, which needs a database.
     */
    public function testACloneTheUserMayCreateRuns(): void
    {
        $checker = $this->createMock(RecordPermissionChecker::class);
        // a page source is asked DC_Table's copy question (v0.19.3), not the edit right
        $checker->expects($this->once())->method('recordCopyDenialReason')->with($this->anything(), 'tl_page', 4)->willReturn(null);
        $checker->expects($this->never())->method('assertRecordAccess');
        $checker->expects($this->once())->method('assertCanCreateContainer')->with($this->anything(), 'tl_page', 4);

        self::assertStringContainsString('"status":"ok"', $this->cloneTool($checker, 1)->cloneRecord('tl_page', 4));
    }

    /**
     * Every page of the subtree must be copyable, asked with DC_Table's clipboard question;
     * one that is not stops the clone before the core runs.
     */
    public function testARecursiveCloneStopsAtASubpageTheUserMayNotCopy(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static fn (string $sql, array $params): array => 4 === $params[0] ? [7, 8] : [],
        );

        $asked = [];
        $checker = $this->createMock(RecordPermissionChecker::class);
        $checker->method('recordCopyDenialReason')->willReturnCallback(
            static function (BackendUser $user, string $table, int $id) use (&$asked): ?string {
                $asked[] = [$table, $id];

                return 8 === $id ? 'verweigert' : null;
            },
        );

        try {
            $this->cloneTool($checker, 0, $connection)->cloneRecord('tl_page', 4, [], true);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('8 (verweigert)', $e->getMessage());
        }
        // the source first (v0.19.3), then every page of the subtree, the source included
        self::assertSame([['tl_page', 4], ['tl_page', 4], ['tl_page', 7], ['tl_page', 8]], $asked);
    }

    /**
     * Children the user may not edit are left out with their reason; the others are
     * rewritten.
     */
    public function testARecursiveRewriteSkipsChildrenTheUserMayNotEdit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 5], ['id' => 6]]);

        $checker = $this->createMock(RecordPermissionChecker::class);
        $checker->method('recordAccessDenialReason')->willReturnCallback(
            static fn (BackendUser $user, string $table, int $id, string $operation): ?string => 'tl_news' === $table && 6 === $id && 'edit' === $operation ? 'verweigert' : null,
        );

        $rewritten = self::ids();
        $rewriter = new class($rewritten) implements EntityRewriterInterface {
            /** @param \ArrayObject<int, int> $rewritten */
            public function __construct(private readonly \ArrayObject $rewritten)
            {
            }

            public function supports(string $table): bool
            {
                return 'tl_news' === $table;
            }

            public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
            {
                $this->rewritten->append($id);

                return ['id' => $id, 'table' => 'tl_news', 'fields' => ['headline' => 'Neu'], 'skipped' => []];
            }
        };

        $resolver = $this->createMock(PlatformResolver::class);
        $resolver->method('resolve')->willReturn(new ResolvedPlatform(
            $this->createMock(PlatformInterface::class),
            'model',
            new PlatformDescriptor('test', 'Test', null, 'test/test'),
        ));

        $tool = new RecordRewriteTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $connection,
            [$rewriter],
            $resolver,
            $checker,
            newsUpdate: $this->command(NewsUpdateCommand::class, 1, '{"status":"ok","id":5}'),
        );
        $this->wire($tool);

        $output = $tool->rewriteRecord('tl_news_archive', 1, 'kürzer', true);

        self::assertSame([5], $rewritten->getArrayCopy());
        self::assertStringContainsString('"refused":[{"id":6,"reason":"verweigert"}]', $output);
    }

    /**
     * record_list shows only the rows the voters let the user read, asked with the full
     * stored row, not the columns the list carries.
     */
    public function testTheListShowsOnlyRowsTheUserMayRead(): void
    {
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(
            static fn (string $table, int $id): array => ['id' => $id, 'pid' => 1, 'headline' => 'voll'],
        );
        $records->method('isGranted')->willReturnCallback(
            static fn (ReadAction $action): bool => 'tl_news' === $action->getDataSource() && 'voll' === $action->getCurrent()['headline'] && 7 !== (int) $action->getCurrentId(),
        );

        $tool = new RecordListTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->command(RecordListCommand::class, 1, '{"status":"ok","table":"tl_news","total":3,"results":[{"id":5},{"id":7},{"id":9}]}'),
        );
        $this->wire($tool, $records);

        $output = $tool->listRecords('tl_news');

        self::assertStringContainsString('"count":2', $output);
        self::assertStringContainsString('{"id":5}', $output);
        self::assertStringNotContainsString('{"id":7}', $output);
        self::assertStringContainsString('{"id":9}', $output);
    }

    /**
     * v0.19.0: a variant shows only with its size — Contao's variant voter does not look
     * at the size, and the allow-list `imageSizes` sits on the size.
     */
    public function testAVariantIsListedOnlyWithASizeTheUserMayRead(): void
    {
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): array => match ($table) {
            'tl_image_size_item' => ['id' => $id, 'pid' => 20 === $id ? 4 : 9],
            default              => ['id' => $id],
        });
        $records->method('isGranted')->willReturnCallback(
            static fn (ReadAction $action): bool => 'tl_image_size' !== $action->getDataSource() || 4 === (int) $action->getCurrentId(),
        );

        $tool = new RecordListTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->command(RecordListCommand::class, 1, '{"status":"ok","table":"tl_image_size_item","total":2,"results":[{"id":20},{"id":21}]}'),
        );
        $this->wire($tool, $records);
        $themes = $this->createMock(BackendUser::class);
        $themes->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin' => false,
            'id'      => 3,
            'modules' => ['themes'],
            default   => null,
        });
        $themes->method('__isset')->willReturn(true);
        $tool->setActingUserOverride($themes);

        $output = $tool->listRecords('tl_image_size_item');

        self::assertStringContainsString('{"id":20}', $output);
        self::assertStringNotContainsString('{"id":21}', $output, 'its size 9 is not readable');
    }
}
