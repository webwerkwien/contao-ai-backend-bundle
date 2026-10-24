<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;

/**
 * The clones of core v1.8.0 in the chat (v0.19.0): form, newsletter channel, module and
 * layout, through the real tool, and the question before a channel takes its
 * recipients along — they consented to the source channel, not to the copy.
 */
class RecordCloneNewTablesTest extends TestCase
{
    /** @var list<InputInterface> */
    private array $inputs = [];

    /**
     * @param list<string> $modules
     */
    private function user(array $modules, bool $isAdmin = false): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => $isAdmin,
            'id'       => 3,
            'username' => 'redakteur',
            'modules'  => $modules,
            default    => null,
        });
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'id', 'username', 'modules'], true));

        return $user;
    }

    /**
     * An admin by default: after a form or channel clone an editor gets the new record in
     * their allow-list through UserModel, which needs a database.
     */
    private function tool(
        int $runs,
        ?PendingActionStore $store = null,
        bool $coreKnowsRecipients = true,
        ?RecordPermissionChecker $checker = null,
        ?BackendUser $user = null,
        ?Connection $connection = null,
    ): RecordCloneTool {
        $definition = new InputDefinition([
            new InputOption('source-table', null, InputOption::VALUE_REQUIRED),
            new InputOption('source-id', null, InputOption::VALUE_REQUIRED),
            new InputOption('modifications', null, InputOption::VALUE_OPTIONAL),
            new InputOption('recursive', null, InputOption::VALUE_NONE),
        ]);
        if ($coreKnowsRecipients) {
            $definition->addOption(new InputOption('with-recipients', null, InputOption::VALUE_NONE));
        }

        $command = $this->createMock(RecordCloneCommand::class);
        $command->method('getDefinition')->willReturn($definition);
        $command->expects($this->exactly($runs))->method('run')->willReturnCallback(
            function (InputInterface $input, OutputInterface $output): int {
                $this->inputs[] = $input;
                $output->write('{"status":"ok","id":9,"table":"tl_newsletter_channel","count":2}');

                return 0;
            },
        );

        if (null === $connection) {
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchOne')->willReturnCallback(
                static fn (string $sql): string => str_contains($sql, 'COUNT(*)') ? '12' : 'Kundenbrief',
            );
        }

        $tool = new RecordCloneTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $command,
            $checker ?? $this->createMock(RecordPermissionChecker::class),
            $this->createMock(ContaoFramework::class),
            $connection,
        );
        $tool->setFieldPermission($this->createMock(FieldPermission::class));
        $tool->setPendingActionStore($store ?? $this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($this->createMock(DataContainerAccess::class));
        $tool->setActingUserOverride($user ?? $this->user([], true));

        return $tool;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function newSources(): iterable
    {
        yield 'form'               => ['tl_form'];
        yield 'newsletter channel' => ['tl_newsletter_channel'];
        yield 'module'             => ['tl_module'];
        yield 'layout'             => ['tl_layout'];
    }

    /**
     * @dataProvider newSources
     */
    public function testTheNewTablesAreClonedAfterTheRightsCheck(string $table): void
    {
        $checker = $this->createMock(RecordPermissionChecker::class);
        $checker->expects($this->once())->method('assertRecordAccess')->with($this->anything(), $table, 4, 'read');
        $checker->expects($this->once())->method('assertCanCreateContainer')->with($this->anything(), $table, 4);

        self::assertStringContainsString('"status":"ok"', $this->tool(1, checker: $checker)->cloneRecord($table, 4));
        self::assertSame($table, $this->inputs[0]->getParameterOption('--source-table'));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function moduleSets(): iterable
    {
        yield 'form generator' => [['form'], true];
        yield 'newsletter'     => [['newsletter'], true];
        yield 'themes'         => [['themes'], true];
        yield 'files only'     => [['files'], false];
    }

    /**
     * @dataProvider moduleSets
     *
     * @param list<string> $modules
     */
    public function testTheToolIsOfferedToWhoeverHasASourceModule(array $modules, bool $offered): void
    {
        self::assertSame($offered, $this->tool(0)->isAccessibleBy($this->user($modules)));
    }

    public function testRecipientsAreAskedForBeforeAnythingIsCloned(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->method('stagedInCurrentTurn')->willReturn(false);
        $store->method('consume')->willReturn(null);
        $store->expects($this->once())->method('stage')->with(3, 'record_clone', 'recipients:4', $this->anything());

        $output = $this->tool(0, $store)->cloneRecord('tl_newsletter_channel', 4, [], false, true);

        self::assertStringContainsString('"status":"pending_confirmation"', $output);
        self::assertStringContainsString('Kundenbrief', $output);
        self::assertStringContainsString('12 Abonnenten', $output);
    }

    public function testAConfirmedCloneTakesTheRecipients(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->method('stagedInCurrentTurn')->willReturn(false);
        $store->method('consume')->with(3, 'record_clone', 'recipients:4')->willReturn(['sourceId' => 4]);
        $store->expects($this->never())->method('stage');

        $this->tool(1, $store)->cloneRecord('tl_newsletter_channel', 4, [], false, true);

        self::assertTrue($this->inputs[0]->hasParameterOption('--with-recipients'));
    }

    public function testWithoutRecipientsNothingIsAskedAndNoneAreCopied(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method('stage');
        $store->expects($this->never())->method('consume');

        $this->tool(1, $store)->cloneRecord('tl_newsletter_channel', 4);

        self::assertFalse($this->inputs[0]->hasParameterOption('--with-recipients'));
    }

    /**
     * The CLI asked before it sent the flag; a question staged here could never be
     * answered, there is no later chat message.
     */
    public function testTheBridgeDoesNotAskAgain(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method('stage');
        $store->expects($this->never())->method('consume');

        $this->tool(1, $store)->cloneFromBridge('tl_newsletter_channel', 4, [], false, true);

        self::assertTrue($this->inputs[0]->hasParameterOption('--with-recipients'));
    }

    public function testRecipientsForAnotherTableAreRefused(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('nur für tl_newsletter_channel');

        $this->tool(0)->cloneRecord('tl_form', 4, [], false, true);
    }

    public function testAnOlderCoreRefusesRecipientsInsteadOfFailing(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('ab v1.8.0');

        $this->tool(0, coreKnowsRecipients: false)->cloneRecord('tl_newsletter_channel', 4, [], false, true);
    }

    /**
     * @param list<string> $forms the merged allow-list BackendUser holds
     */
    private function editorWho(string $inherit, array $forms = []): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'id'       => 3,
            'username' => 'h.lewis',
            'modules'  => ['form'],
            'inherit'  => $inherit,
            'groups'   => ['1', '4'],
            'forms'    => $forms,
            default    => null,
        });
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['isAdmin', 'id', 'username', 'modules', 'inherit', 'groups', 'forms'], true));

        return $user;
    }

    /**
     * @param list<array{string, array<string, string>, array<string, int>}> $updates collects every write
     */
    private function allowListDb(array &$updates): Connection
    {
        $connection = $this->createMock(Connection::class);
        // Only the user's own groups (a wrong id list stayed green while the mock
        // ignored its parameters).
        $connection->method('fetchAllAssociative')
            ->with('SELECT id, forms FROM tl_user_group WHERE id IN (?)', [[1, 4]], [ArrayParameterType::INTEGER])
            ->willReturn([
                ['id' => 1, 'forms' => serialize(['5'])],
                ['id' => 4, 'forms' => null],
            ]);
        $connection->method('fetchAssociative')->willReturn(['id' => 3, 'forms' => serialize(['2'])]);
        $connection->method('update')->willReturnCallback(
            static function (string $table, array $data, array $criteria) use (&$updates): int {
                $updates[] = [$table, $data, $criteria];

                return 1;
            },
        );

        return $connection;
    }

    /**
     * @return iterable<string, array{string, list<array{string, array<string, string>, array<string, int>}>}>
     */
    public static function inheritance(): iterable
    {
        $groups = [
            ['tl_user_group', ['forms' => serialize(['5', '9'])], ['id' => 1]],
            ['tl_user_group', ['forms' => serialize(['9'])], ['id' => 4]],
        ];
        $own = ['tl_user', ['forms' => serialize(['2', '9'])], ['id' => 3]];

        // As UserRootListener::adjustPermissions(): groups unless custom, the user unless group.
        yield 'group only'   => ['group', $groups];
        yield 'extend'       => ['extend', [...$groups, $own]];
        yield 'custom'       => ['custom', [$own]];
    }

    /**
     * Until v0.19.0 only the user's own list got the id: with `inherit = group` BackendUser
     * ignores it, the editor did not see the clone, and the answer said `auto_granted`.
     *
     * @dataProvider inheritance
     *
     * @param list<array{string, array<string, string>, array<string, int>}> $expected
     */
    public function testTheCloneJoinsTheAllowListsContaoWouldWrite(string $inherit, array $expected): void
    {
        $updates = [];
        $output = $this->tool(1, user: $this->editorWho($inherit), connection: $this->allowListDb($updates))
            ->cloneRecord('tl_form', 4);

        self::assertSame($expected, $updates);
        self::assertStringContainsString('"auto_granted"', $output);
    }

    public function testNothingIsWrittenWhenTheMergedListHasTheCloneAlready(): void
    {
        $updates = [];
        $output = $this->tool(1, user: $this->editorWho('group', ['9']), connection: $this->allowListDb($updates))
            ->cloneRecord('tl_form', 4);

        self::assertSame([], $updates);
        self::assertStringNotContainsString('"auto_granted"', $output);
    }

    /**
     * A clone the rights refuse is not staged as a question first.
     */
    public function testNoQuestionForACloneTheRightsRefuse(): void
    {
        $checker = $this->createMock(RecordPermissionChecker::class);
        $checker->method('assertCanCreateContainer')->willThrowException(new ToolAccessDeniedException('kein Anlage-Recht'));
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method('stage');

        $this->expectExceptionMessage('kein Anlage-Recht');

        $this->tool(0, $store, checker: $checker)->cloneRecord('tl_newsletter_channel', 4, [], false, true);
    }
}
