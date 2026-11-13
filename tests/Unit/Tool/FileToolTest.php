<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachment;
use Webwerkwien\ContaoAiBackendBundle\Attachment\ChatAttachments;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\ConsoleRunner;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tool\FileTool;

/**
 * The file manager in the chat: the rights the back end asks for, the questions it does
 * not ask (usages before rename, move and delete; a name that exists before storing),
 * and that nothing is written before the user answered.
 */
class FileToolTest extends TestCase
{
    private string $root;

    /** @var list<array<string, mixed>> */
    private array $runs = [];

    /** @var array<string, array<string, mixed>> staged payloads by key */
    private array $staged = [];

    /** @var array<string, true> keys staged in the current turn */
    private array $stagedNow = [];

    /** @var list<string> the bytes contao:file:write was given */
    private array $written = [];

    /** @var list<array{string, mixed}> what the tool asked the authorization checker */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-tool-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/files/team', 0777, true);
        mkdir($this->root . '/files/other', 0777, true);
        file_put_contents($this->root . '/files/team/foto.jpg', 'old');
        $this->runs = [];
        $this->staged = [];
        $this->stagedNow = [];
        $this->written = [];
        $this->asked = [];
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root);
    }

    /**
     * @param list<string>          $denied     attributes the user does not have
     * @param list<array<mixed>>    $usages     what contao:file:usages answers
     * @param list<ChatAttachment> $attachments
     */
    private function tool(array $denied = [], array $usages = [], array $attachments = []): FileTool
    {
        $access = $this->createMock(ToolAccessChecker::class);
        $access->method('canUseTool')->willReturn(true);

        $console = $this->createMock(ConsoleRunner::class);
        $console->method('has')->willReturn(true);
        $console->method('run')->willReturnCallback(function (array $input) use ($usages): array {
            $this->runs[] = $input;
            if ('contao:file:usages' === $input['command']) {
                return [0, json_encode(['status' => 'ok', 'usages' => $usages])];
            }
            if ('contao:file:write' === $input['command']) {
                // The temp file is there while the core reads it.
                self::assertFileExists($input['--source']);
                $this->written[] = (string) file_get_contents($input['--source']);
            }

            return [0, '{"status":"ok","uuid":"abc"}'];
        });

        $chat = new ChatAttachments();
        $chat->set($attachments);

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(function (string $attribute, mixed $subject = null) use ($denied): bool {
            $this->asked[] = [$attribute, $subject];
            if (ContaoCorePermissions::USER_CAN_ACCESS_PATH === $attribute) {
                // As BackendAccessVoter: regardless of case.
                return 0 === stripos((string) $subject, 'files/team');
            }

            return !\in_array($attribute, $denied, true);
        });

        $store = $this->createMock(PendingActionStore::class);
        $store->method('peek')->willReturnCallback(fn (int $u, string $t, string $k): ?array => $this->staged[$k] ?? null);
        $store->method('stagedInCurrentTurn')->willReturnCallback(fn (int $u, string $t, string $k): bool => isset($this->stagedNow[$k]));
        $store->method('stage')->willReturnCallback(function (int $u, string $t, string $k, array $p): void {
            $this->staged[$k] = $p;
            $this->stagedNow[$k] = true;
        });
        $store->method('consume')->willReturnCallback(function (int $u, string $t, string $k): ?array {
            if (isset($this->stagedNow[$k]) || !isset($this->staged[$k])) {
                return null;
            }
            $p = $this->staged[$k];
            unset($this->staged[$k]);

            return $p;
        });

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) { 'id' => 5, 'isAdmin' => false, 'username' => 'redakteur', 'filemounts' => ['files/team'], default => null });
        $user->method('__isset')->willReturnCallback(static fn (string $key): bool => \in_array($key, ['id', 'isAdmin', 'username', 'filemounts'], true));

        $tool = new FileTool($access, $this->createStub(TokenChecker::class), $console, $chat, $this->root);
        $tool->setAuthorizationChecker($auth);
        $tool->setPendingActionStore($store);
        $tool->setActingUserOverride($user);

        return $tool;
    }

    /** The user's next message: what was staged can now be consumed. */
    private function nextTurn(): void
    {
        $this->stagedNow = [];
    }

    /** @return list<string> */
    private function commands(): array
    {
        return array_map(static fn (array $r): string => (string) $r['command'], $this->runs);
    }

    public function testAPathOutsideTheFileMountsIsRefusedBeforeAnyCommand(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        try {
            $this->tool()->usages('files/other/a.jpg');
        } finally {
            self::assertSame([], $this->runs);
        }
    }

    public function testAPathOutsideFilesIsRefused(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->tool()->usages('files/../config/parameters.yml');
    }

    public function testDeletingWithoutTheDeleteRightRunsNothing(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        try {
            $this->tool(denied: [ContaoCorePermissions::USER_CAN_DELETE_FILE])->delete('files/team/foto.jpg');
        } finally {
            self::assertSame([], $this->runs);
        }
    }

    public function testAFolderWithContentNeedsTheRecursiveDeleteRight(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool(denied: [ContaoCorePermissions::USER_CAN_DELETE_RECURSIVELY])->delete('files/team');
    }

    public function testAFileInUseIsNotDeletedAndTheAnswerSaysWhere(): void
    {
        try {
            $this->tool(usages: [['table' => 'tl_content', 'id' => 650, 'field' => 'singleSRC']])->delete('files/team/foto.jpg');
            self::fail('expected a refusal');
        } catch (ToolRefusedException $e) {
            self::assertStringContainsString('tl_content 650 (singleSRC)', $e->getMessage());
        }
        self::assertSame(['contao:file:usages'], $this->commands());
        self::assertArrayNotHasKey('--paths-only', $this->runs[0], 'a delete looks for UUID references too');
    }

    public function testAnUnusedFileIsDeletedOnlyAfterTheUserSaidYes(): void
    {
        $tool = $this->tool();

        self::assertStringContainsString('pending_confirmation', $tool->delete('files/team/foto.jpg'));
        self::assertNotContains('contao:file:delete', $this->commands());

        $this->nextTurn();
        $tool->delete('files/team/foto.jpg');

        self::assertContains('contao:file:delete', $this->commands());
        $delete = $this->runs[array_search('contao:file:delete', $this->commands(), true)];
        self::assertArrayNotHasKey('--force', $delete);
    }

    public function testARenameRunsAtOnceWhenNoTextNamesThePath(): void
    {
        $this->tool()->rename('files/team/foto.jpg', 'team.jpg');

        self::assertSame(['contao:file:usages', 'contao:file:rename'], $this->commands());
        self::assertTrue($this->runs[0]['--paths-only']);
    }

    public function testARenameAsksWhenATextFieldNamesThePath(): void
    {
        $tool = $this->tool(usages: [['table' => 'tl_content', 'id' => 7, 'field' => 'text']]);

        $answer = $tool->rename('files/team/foto.jpg', 'team.jpg');

        self::assertStringContainsString('tl_content 7 (text)', $answer);
        self::assertSame(['contao:file:usages'], $this->commands());
    }

    public function testAMoveNeedsTheTargetInsideTheMounts(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool()->move('files/team/foto.jpg', 'files/other');
    }

    public function testStoringANewNameWritesAndRemovesTheTempFile(): void
    {
        $this->tool(attachments: [new ChatAttachment('neu.jpg', 'jpg', 'image/jpeg', 'bytes')])->store('neu.jpg', 'files/team');

        self::assertSame(['contao:file:write'], $this->commands());
        self::assertSame('files/team/neu.jpg', $this->runs[0]['--path']);
        self::assertFileDoesNotExist($this->runs[0]['--source']);
    }

    public function testAnExistingNameIsAskedOnceAndNothingIsWritten(): void
    {
        $answer = $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');

        self::assertStringContainsString('pending_confirmation', $answer);
        self::assertStringContainsString('foto-1.jpg', $answer);
        self::assertStringNotContainsString('chat-', $answer, 'the temp name stays on the server');
        self::assertSame([], $this->runs);
        self::assertSame('old', file_get_contents($this->root . '/files/team/foto.jpg'));
    }

    public function testReplaceInTheNextMessageWritesTheStagedFile(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        // The attachment belongs to the earlier message and is gone now.
        $this->tool()->store('foto.jpg', 'files/team', '', 'replace');

        self::assertSame(['contao:file:write'], $this->commands());
        self::assertSame('files/team/foto.jpg', $this->runs[0]['--path']);
    }

    public function testRenameInTheNextMessageTakesTheFreeName(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        $this->tool()->store('foto.jpg', 'files/team', '', 'rename');

        self::assertSame('files/team/foto-1.jpg', $this->runs[0]['--path']);
    }

    public function testReplaceInTheSameTurnWritesNothing(): void
    {
        $tool = $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')]);
        $tool->store('foto.jpg', 'files/team');

        $answer = $tool->store('foto.jpg', 'files/team', '', 'replace');

        self::assertStringContainsString('awaiting_user', $answer);
        self::assertSame([], $this->runs);
    }

    public function testReplaceWithoutAQuestionAsksFirst(): void
    {
        $answer = $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team', '', 'replace');

        self::assertStringContainsString('pending_confirmation', $answer);
        self::assertSame([], $this->runs);
        self::assertSame('old', file_get_contents($this->root . '/files/team/foto.jpg'));
    }

    public function testAnotherSpellingOfTheNameDoesNotAnswerTheQuestion(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'first')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        // On a case-sensitive file system Foto.jpg is another file than foto.jpg.
        $answer = $this->tool(attachments: [new ChatAttachment('Foto.jpg', 'jpg', 'image/jpeg', 'second')])->store('Foto.jpg', 'files/team', '', 'replace');

        if (file_exists($this->root . '/files/team/Foto.jpg')) {
            // Here both names are one file: it is replaced only after its own question.
            self::assertStringContainsString('pending_confirmation', $answer);
            self::assertSame([], $this->runs);
        } else {
            self::assertSame(['files/team/Foto.jpg'], array_column($this->runs, '--path'));
        }
    }

    public function testTwoAttachmentsForOneTargetInOneTurnAreRefused(): void
    {
        $tool = $this->tool(attachments: [
            new ChatAttachment('a.jpg', 'jpg', 'image/jpeg', 'a'),
            new ChatAttachment('b.jpg', 'jpg', 'image/jpeg', 'b'),
        ]);
        $tool->store('a.jpg', 'files/team', 'foto.jpg');

        $this->expectException(ToolRefusedException::class);
        $tool->store('b.jpg', 'files/team', 'foto.jpg');
    }

    public function testAnAnswerNamingAnotherAttachmentIsRefusedAndTheQuestionStays(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        try {
            $this->tool()->store('anders.jpg', 'files/team', 'foto.jpg', 'replace');
            self::fail('expected a refusal');
        } catch (ToolRefusedException) {
        }
        self::assertSame([], $this->runs);
        self::assertArrayHasKey('store:files/team/foto.jpg', $this->staged);
    }

    public function testAFolderThatOnlyBeginsLikeTheMountIsOutside(): void
    {
        // This test's voter stub grants any path with the mount's prefix; the tool's own
        // check still treats the mount as a folder.
        mkdir($this->root . '/files/teamwork');
        $this->expectException(ToolAccessDeniedException::class);

        try {
            $this->tool()->createFolder('files/teamwork/neu');
        } finally {
            self::assertSame([], $this->runs);
        }
    }

    public function testARenameAsksForTheNameFieldOfTlFiles(): void
    {
        $this->tool()->rename('files/team/foto.jpg', 'team.jpg');

        self::assertContains([ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_files::name'], $this->asked);
    }

    public function testATamperedTempFileIsNotWritten(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();
        $temp = $this->root . '/var/bridge-uploads/' . $this->staged['store:files/team/foto.jpg']['temp'];
        file_put_contents($temp, 'other');

        $this->expectException(ToolRefusedException::class);
        $this->tool()->store('foto.jpg', 'files/team', '', 'replace');
    }

    public function testATempFileOlderThanTenMinutesIsRemoved(): void
    {
        mkdir($this->root . '/var/bridge-uploads', 0777, true);
        $old = $this->root . '/var/bridge-uploads/chat-' . str_repeat('a', 24) . '.jpg';
        file_put_contents($old, 'x');
        touch($old, time() - 601);

        $this->tool(attachments: [new ChatAttachment('neu.jpg', 'jpg', 'image/jpeg', 'bytes')])->store('neu.jpg', 'files/team');

        self::assertFileDoesNotExist($old);
    }

    public function testAFolderSpelledDifferentlyIsNotTheMount(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        try {
            $this->tool()->createFolder('files/TEAM/neu');
        } finally {
            self::assertSame([], $this->runs);
        }
    }

    public function testAFolderIsCreatedInsideTheMount(): void
    {
        $this->tool()->createFolder('files/team/2026');

        self::assertSame([['command' => 'contao:folder:create', '--path' => 'files/team/2026', '--operator' => 'redakteur']], $this->runs);
    }

    public function testAFolderNeedsTheUploadRight(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool(denied: [ContaoCorePermissions::USER_CAN_UPLOAD_FILES])->createFolder('files/team/2026');
    }

    public function testStoringOutsideTheMountIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool(attachments: [new ChatAttachment('neu.jpg', 'jpg', 'image/jpeg', 'bytes')])->store('neu.jpg', 'files/other');
    }

    public function testDeletingOutsideTheMountIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        try {
            $this->tool()->delete('files/other');
        } finally {
            self::assertSame([], $this->runs);
        }
    }

    public function testAFolderWithOnlyDotFilesNeedsNoRecursiveRight(): void
    {
        mkdir($this->root . '/files/team/leer');
        file_put_contents($this->root . '/files/team/leer/.public', '');

        $answer = $this->tool(denied: [ContaoCorePermissions::USER_CAN_DELETE_RECURSIVELY])->delete('files/team/leer');

        self::assertStringContainsString('pending_confirmation', $answer);
    }

    public function testARenameNeedsTheNameField(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->tool(denied: [ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE])->rename('files/team/foto.jpg', 'team.jpg');
    }

    public function testMetadataIsSetInTheGivenLanguage(): void
    {
        $this->tool()->meta('files/team/foto.jpg', ['alt' => 'Das Team', 'title' => 'Team'], 'de');

        self::assertSame(
            [['command' => 'contao:file:meta', '--path' => 'files/team/foto.jpg', '--lang' => 'de', '--set' => ['alt=Das Team', 'title=Team'], '--operator' => 'redakteur']],
            $this->runs,
        );
    }

    public function testMetadataArrivesAsTheListTheModelSends(): void
    {
        $this->tool()->meta('files/team/foto.jpg', '["alt", "Das Team"]', 'de');
        $this->tool()->meta('files/team/foto.jpg', '{"caption": "Bild"}', 'de');

        self::assertSame([['alt=Das Team'], ['caption=Bild']], array_column($this->runs, '--set'));
    }

    public function testMetadataTakesOnlyItsFourFields(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->tool()->meta('files/team/foto.jpg', ['path' => 'files/x.jpg'], 'de');
    }

    public function testReplaceWithTheFileAttachedAgainStoresTheNewFile(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'first')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'second')])->store('foto.jpg', 'files/team', '', 'replace');

        self::assertSame(['second'], $this->written);
        self::assertSame([], glob($this->root . '/var/bridge-uploads/chat-*'), 'no temp file is left');
    }

    public function testAfterRenameTheStagedReplaceIsGone(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team', '', 'rename');
        $this->nextTurn();

        $this->expectException(ToolRefusedException::class);
        $this->tool()->store('foto.jpg', 'files/team', '', 'replace');
    }

    public function testTheAnswerMaySpellTheNameDifferently(): void
    {
        $this->tool(attachments: [new ChatAttachment('foto.jpg', 'jpg', 'image/jpeg', 'new')])->store('foto.jpg', 'files/team');
        $this->nextTurn();

        $this->tool()->store('Foto.JPG', 'files/team', '', 'replace');

        self::assertSame('files/team/foto.jpg', $this->runs[0]['--path']);
    }

    public function testANewNameKeepsTheExtension(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->tool(attachments: [new ChatAttachment('neu.jpg', 'jpg', 'image/jpeg', 'bytes')])->store('neu.jpg', 'files/team', 'neu.php');
    }
}
