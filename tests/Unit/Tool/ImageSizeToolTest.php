<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\FieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ImageSizeItemTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\ImageSizeTool;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeItemUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * Image sizes in the chat (v0.19.0), through the real tools: the rights asked
 * before a command runs, the confirmation before a delete, the allow-list after a create,
 * and that a variant counts as editing its size.
 */
class ImageSizeToolTest extends TestCase
{
    /** @var list<array{string, mixed}> */
    private array $asked = [];

    /** @var list<string> */
    private array $updates = [];

    /** @var list<string> */
    private array $fields = [];

    private function user(bool $isAdmin): BackendUser
    {
        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'    => $isAdmin,
            'id'         => 3,
            'username'   => 'h.lewis',
            'inherit'    => 'extend',
            'groups'     => ['1'],
            'imageSizes' => [],
            'modules'    => ['themes'],
            default      => null,
        });
        $user->method('__isset')->willReturn(true);

        return $user;
    }

    /**
     * @template T of Command
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class, int $runs, string $answer = '{"status":"ok","id":12}'): Command
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

    /**
     * @param array<string, array<int, array<string, mixed>>> $rows
     */
    private function records(array $rows, bool $granted = true): DataContainerAccess
    {
        $records = $this->createMock(DataContainerAccess::class);
        $records->method('fetchRecord')->willReturnCallback(static fn (string $table, int $id): ?array => $rows[$table][$id] ?? null);
        foreach (['assertRead', 'assertUpdate', 'assertDelete'] as $method) {
            $records->method($method)->willReturnCallback(function (string $table, int $id) use ($rows, $granted, $method): array {
                $this->asked[] = [$method, $table . ':' . $id];
                if (!$granted) {
                    throw new ToolAccessDeniedException('verweigert');
                }

                return $rows[$table][$id];
            });
        }
        $records->method('assertCreate')->willReturnCallback(function (string $table, array $new) use ($granted): void {
            $this->asked[] = ['assertCreate', $table];
            if (!$granted) {
                throw new ToolAccessDeniedException('verweigert');
            }
        });

        return $records;
    }

    private function wire(AbstractCoreCommandTool $tool, DataContainerAccess $records, bool $isAdmin, ?PendingActionStore $store = null): void
    {
        $tool->setFieldPermission($this->createMock(FieldPermission::class));
        $tool->setPendingActionStore($store ?? $this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($this->user($isAdmin));
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'imageSizes' => null]]);
        $connection->method('fetchAssociative')->willReturn(['id' => 3, 'imageSizes' => serialize(['2'])]);
        $connection->method('fetchOne')->willReturn('2');
        $connection->method('update')->willReturnCallback(function (string $table, array $data): int {
            $this->updates[] = $table;
            $this->fields = [...$this->fields, ...array_keys($data)];

            return 1;
        });

        return $connection;
    }

    private function sizeTool(int $createRuns, int $deleteRuns, bool $isAdmin, bool $granted = true, ?PendingActionStore $store = null): ImageSizeTool
    {
        $tool = new ImageSizeTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->connection(),
            $this->command(ImageSizeCreateCommand::class, $createRuns),
            $this->command(ImageSizeUpdateCommand::class, 0),
            $this->command(ImageSizeDeleteCommand::class, $deleteRuns),
            $this->command(ImageSizeReadCommand::class, 0),
        );
        $this->wire($tool, $this->records([
            'tl_theme'      => [1 => ['id' => 1, 'name' => 'Basis']],
            'tl_image_size' => [4 => ['id' => 4, 'pid' => 1, 'name' => 'Teaser']],
        ], $granted), $isAdmin, $store);

        return $tool;
    }

    private function itemTool(string $method, bool $granted = true): ImageSizeItemTool
    {
        $tool = new ImageSizeItemTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->command(ImageSizeItemCreateCommand::class, 'create' === $method && $granted ? 1 : 0),
            $this->command(ImageSizeItemUpdateCommand::class, 'update' === $method && $granted ? 1 : 0),
            $this->command(ImageSizeItemDeleteCommand::class, 0),
            $this->command(ImageSizeItemReadCommand::class, 'read' === $method && $granted ? 1 : 0),
        );
        $this->wire($tool, $this->records([
            'tl_image_size'      => [4 => ['id' => 4, 'pid' => 1, 'name' => 'Teaser']],
            'tl_image_size_item' => [20 => ['id' => 20, 'pid' => 4, 'media' => '(max-width: 600px)']],
        ], $granted), false);

        return $tool;
    }

    public function testASizeIsCreatedAfterTheCreateRightAndJoinsTheAllowLists(): void
    {
        $output = $this->sizeTool(1, 0, false)->create(1, 'Hero', ['width' => '1200']);

        self::assertContains(['assertCreate', 'tl_image_size'], $this->asked);
        self::assertStringContainsString('"auto_granted"', $output);
        self::assertSame(['tl_user_group', 'tl_user'], $this->updates, 'groups unless custom, the user unless group');
        self::assertSame(['imageSizes', 'imageSizes'], $this->fields, 'the userRoot of tl_image_size');
    }

    public function testAnAdminGetsNoAllowListEntry(): void
    {
        $output = $this->sizeTool(1, 0, true)->create(1, 'Hero');

        self::assertStringNotContainsString('"auto_granted"', $output);
        self::assertSame([], $this->updates);
    }

    public function testASizeInAMissingThemeIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);

        $this->sizeTool(0, 0, false)->create(9, 'Hero');
    }

    public function testWithoutTheRightNothingIsCreated(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->sizeTool(0, 0, false, false)->create(1, 'Hero');
    }

    public function testADeleteAsksFirst(): void
    {
        $store = $this->createMock(PendingActionStore::class);
        $store->method('stagedInCurrentTurn')->willReturn(false);
        $store->method('consume')->willReturn(null);
        $store->expects($this->once())->method('stage')->with(3, 'image_size_delete', '4', $this->anything());

        $output = $this->sizeTool(0, 0, false, true, $store)->delete(4);

        self::assertStringContainsString('"pending_confirmation"', $output);
        self::assertStringContainsString('Teaser', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function itemMethods(): iterable
    {
        yield 'create' => ['create'];
        yield 'update' => ['update'];
        yield 'read'   => ['read'];
    }

    /**
     * Contao's variant voter does not look at the size; a variant is part of it, so the
     * size is asked too — which includes its allow-list.
     *
     * @dataProvider itemMethods
     */
    public function testAVariantAsksForItsSize(string $method): void
    {
        $tool = $this->itemTool($method);
        match ($method) {
            'create' => $tool->create(4, '(max-width: 400px)'),
            'update' => $tool->update(20, ['width' => '300']),
            default  => $tool->read(20),
        };

        $sizeQuestions = array_filter($this->asked, static fn (array $a): bool => 'tl_image_size:4' === $a[1]);
        self::assertNotEmpty($sizeQuestions, 'the size the variant belongs to was asked');
    }

    public function testAVariantOfASizeTheEditorMayNotEditIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->itemTool('create', false)->create(4, '(max-width: 400px)');
    }
}
