<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformDescriptor;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ResolvedPlatform;
use Webwerkwien\ContaoAiBackendBundle\Service\Rewriter\EntityRewriterInterface;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\PageTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordCloneTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\RecordRewriteTool;
use Webwerkwien\ContaoAiCoreBundle\Command\PageCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PagePublishCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\PageUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\RecordCloneCommand;

/**
 * The field rights reach the tools: each write path is called through the real tool,
 * with the real FieldPermission, and a refused write never runs its command (rule 24:
 * the test calls the place, it does not copy it).
 *
 * The record-level checks (page mounts, archives) are switched off in the tool
 * subclasses below; they are RecordPermissionChecker's and tested there.
 */
class FieldRightsWiringTest extends TestCase
{
    private mixed $dcaBefore;

    protected function setUp(): void
    {
        $this->dcaBefore = $GLOBALS['TL_DCA'] ?? null;
        $GLOBALS['TL_DCA']['tl_page'] = [
            'config' => ['dataContainer' => 'Contao\DC_Table'],
            'fields' => [
                'title'       => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
                'alias'       => ['inputType' => 'text', 'sql' => "varchar(255) BINARY NOT NULL default ''"],
                'type'        => ['inputType' => 'select', 'sql' => "varchar(64) NOT NULL default 'regular'"],
                'published'   => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
                'description' => ['inputType' => 'textarea', 'sql' => 'text NULL'],
                'pageTitle'   => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if (null === $this->dcaBefore) {
            unset($GLOBALS['TL_DCA']);
        } else {
            $GLOBALS['TL_DCA'] = $this->dcaBefore;
        }
    }

    /**
     * @param list<string> $granted
     */
    private function wire(AbstractCoreCommandTool $tool, array $granted, ?PendingActionStore $store = null): void
    {
        $adapter = $this->createMock(Adapter::class);
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(
            static fn (string $attribute, mixed $subject = null): bool => ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE === $attribute && \in_array($subject, $granted, true),
        );

        $tool->setFieldPermission(new FieldPermission($framework, $auth));
        $tool->setAuthorizationChecker($auth);
        $tool->setPendingActionStore($store ?? $this->createMock(PendingActionStore::class));
        $tool->setActingUserOverride($this->editor());
    }

    private function editor(): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'username' => 'redakteur',
            default    => null,
        });

        return $user;
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     *
     * @return T&MockObject
     */
    private function command(string $class, bool $runs, string $answer = '{"status":"ok","id":5}'): Command
    {
        $command = $this->createMock($class);
        $command->method('getDefinition')->willReturn(new InputDefinition());
        $command->expects($runs ? $this->once() : $this->never())->method('run')->willReturnCallback(
            static function (InputInterface $input, OutputInterface $output) use ($answer): int {
                $output->write($answer);

                return 0;
            },
        );

        return $command;
    }

    private function pageTool(bool $updateRuns = false, bool $createRuns = false, bool $publishRuns = false): PageTool
    {
        return new class(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->command(PageCreateCommand::class, $createRuns),
            $this->command(PageUpdateCommand::class, $updateRuns),
            $this->command(PageDeleteCommand::class, false),
            $this->command(PageReadCommand::class, false),
            $this->command(PagePublishCommand::class, $publishRuns),
        ) extends PageTool {
            protected function assertRecordAccess(int $recordId, string $operation): void
            {
            }

            protected function assertPageOperation(int $pageId, string $permission): void
            {
            }
        };
    }

    public function testPageUpdateWithoutTheFieldRightRunsNothing(): void
    {
        $tool = $this->pageTool();
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/tl_page\.published/');

        $tool->update(5, ['title' => 'Neu', 'published' => '1']);
    }

    public function testPageUpdateWithTheRightRuns(): void
    {
        $tool = $this->pageTool(updateRuns: true);
        $this->wire($tool, ['tl_page::title', 'tl_page::published']);

        $this->assertStringContainsString('"status":"ok"', $tool->update(5, ['title' => 'Neu', 'published' => '1']));
    }

    /**
     * The `published` toggle needs that field, and the refusal comes before the
     * "really take it offline?" question — nobody confirms what is then refused.
     */
    public function testPagePublishWithoutTheRightNeitherAsksNorRuns(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->expects($this->never())->method($this->anything());

        $tool = $this->pageTool();
        $this->wire($tool, ['tl_page::title'], $store);

        $this->expectException(ToolAccessDeniedException::class);

        $tool->publish(5, false);
    }

    public function testPagePublishWithTheRightRuns(): void
    {
        $tool = $this->pageTool(publishRuns: true);
        $this->wire($tool, ['tl_page::published']);

        $this->assertStringContainsString('"status":"ok"', $tool->publish(5, true));
    }

    public function testPageCreateWithAnotherTypeNeedsTheTypeRight(): void
    {
        $tool = $this->pageTool();
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/tl_page\.type/');

        $tool->create('Weiterleitung', '', 'forward', 3);
    }

    public function testPageCreateWithTheDefaultTypeNeedsOnlyTheTitleRight(): void
    {
        $tool = $this->pageTool(createRuns: true);
        $this->wire($tool, ['tl_page::title']);

        $this->assertStringContainsString('"status":"ok"', $tool->create('Über uns', '', 'regular', 3));
    }

    private function cloneTool(bool $runs): RecordCloneTool
    {
        return new RecordCloneTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(RecordCloneCommand::class, $runs, '{"status":"ok","id":9,"table":"tl_page"}'),
            $this->createMock(RecordPermissionChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->createMock(Connection::class),
        );
    }

    /**
     * Found by the review of 2026-10-14: {"published": "1"} published a clone for an
     * editor whose group grants no `tl_page::published`.
     */
    public function testACloneOverrideWithoutTheRightRunsNothing(): void
    {
        $tool = $this->cloneTool(false);
        $this->wire($tool, ['tl_page::title']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessageMatches('/tl_page\.published/');

        $tool->cloneRecord('tl_page', 4, ['title' => 'Kopie', 'published' => '1']);
    }

    public function testACloneThatKeepsTheDefaultsNeedsNoRight(): void
    {
        $tool = $this->cloneTool(true);
        $this->wire($tool, []);

        $this->assertStringContainsString('"status":"ok"', $tool->cloneRecord('tl_page', 4, ['published' => '']));
    }

    /**
     * Skipped with its reason, not refused: the fields the user may edit are written,
     * the others appear under `skipped` (Michael, 2026-10-14).
     */
    public function testARewriteSkipsTheFieldsWithoutARight(): void
    {
        $update = $this->createMock(PageUpdateCommand::class);
        $update->method('getDefinition')->willReturn(new InputDefinition());
        $update->expects($this->once())->method('run')->willReturnCallback(
            function (InputInterface $input, OutputInterface $output): int {
                $this->assertSame(['title=New title'], $input->getParameterOption('--set'));
                $output->write('{"status":"ok","id":5}');

                return 0;
            },
        );

        $rewriter = new class implements EntityRewriterInterface {
            public function supports(string $table): bool
            {
                return 'tl_page' === $table;
            }

            public function rewrite(int $id, string $instructions, PlatformInterface $platform, string $model): array
            {
                return ['id' => $id, 'table' => 'tl_page', 'fields' => ['title' => 'New title', 'description' => 'New text'], 'skipped' => []];
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
            $this->createMock(Connection::class),
            [$rewriter],
            $resolver,
            $this->createMock(RecordPermissionChecker::class),
            pageUpdate: $update,
        );
        $this->wire($tool, ['tl_page::title']);

        $answer = $tool->rewriteRecord('tl_page', 5, 'auf Englisch');

        $this->assertStringContainsString('"updated":["title"]', $answer);
        $this->assertStringContainsString('"description":"Kein Recht auf das Feld', $answer);
    }
}
