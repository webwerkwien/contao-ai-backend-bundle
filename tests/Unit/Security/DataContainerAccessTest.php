<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Contao\CoreBundle\Security\Voter\DataContainer\DefaultDataContainerVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;

/**
 * The question asked is the one DC_Table asks: `contao_dc.<table>` with an action that
 * carries the stored row (as `getCurrentRecord()` reads it) and the values a write sets.
 */
class DataContainerAccessTest extends TestCase
{
    /** @var list<array{string, mixed}> */
    private array $asked = [];

    /** @var list<string> */
    private array $loaded = [];

    /**
     * @param array<string, array<int, array<string, mixed>>> $rows table => id => row
     */
    private function access(array $rows, bool $granted = true, bool $signedIn = true): DataContainerAccess
    {
        $tokenStorage = new TokenStorage();
        if ($signedIn) {
            $tokenStorage->setToken(new NullToken());
        }

        $controller = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(['loadDataContainer'])->getMock();
        $controller->method('loadDataContainer')->willReturnCallback(function (string $table): void {
            $this->loaded[] = $table;
        });
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($controller);

        // A Contao data container voter as they all are — "denied" or abstain — in front
        // of Contao's real DefaultDataContainerVoter, which grants last.
        $voter = $this->createMock(VoterInterface::class);
        $voter->method('vote')->willReturnCallback(function (TokenInterface $token, mixed $subject, array $attributes) use ($granted): int {
            $this->asked[] = [$attributes[0], $subject];

            return $granted ? VoterInterface::ACCESS_ABSTAIN : VoterInterface::ACCESS_DENIED;
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params) use ($rows): array|false {
            preg_match('/FROM (\w+) WHERE/', $sql, $m);
            if ('tl_missing' === $m[1]) {
                throw new TableNotFoundException(self::driverException(), null);
            }

            return $rows[$m[1]][(int) $params[0]] ?? false;
        });

        return new DataContainerAccess($framework, $tokenStorage, $connection, [$voter, new DefaultDataContainerVoter()]);
    }

    /**
     * The priority strategy, as in Contao's firewalls, in every context: a voter's
     * "denied" wins over the default voter's "granted" behind it. Under Symfony's
     * default strategy — which the global check uses for the CLI bridge, outside any
     * Contao firewall — the "granted" would win; measured on c5 2026-10-14, where a
     * console script got "allowed" for everything.
     */
    public function testADenialWinsOverTheDefaultVoterBehindIt(): void
    {
        self::assertFalse($this->access([], false)->isGranted(new CreateAction('tl_page', ['pid' => 0, 'sorting' => 0, 'type' => 'regular'])));
        self::assertTrue($this->access([], true)->isGranted(new CreateAction('tl_page', ['pid' => 2, 'sorting' => 0, 'type' => 'regular'])));
    }

    public function testWithoutASignedInUserNothingIsGranted(): void
    {
        self::assertFalse($this->access([], true, false)->isGranted(new CreateAction('tl_page', ['pid' => 1, 'sorting' => 0])));
        self::assertSame([], $this->asked);
    }

    private static function driverException(): DriverException
    {
        return new class('no such table') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }

    public function testAnUpdateAsksWithTheStoredRowAndTheNewValues(): void
    {
        $row = ['id' => 5, 'pid' => 3, 'type' => 'regular', 'title' => 'Alt'];
        $returned = $this->access(['tl_page' => [5 => $row]])->assertUpdate('tl_page', 5, ['title' => 'Neu']);

        self::assertSame($row, $returned);
        self::assertSame(['tl_page'], $this->loaded, 'the DCA is loaded first: TableAccessVoter abstains without it');
        self::assertCount(1, $this->asked);
        [$attribute, $action] = $this->asked[0];
        self::assertSame('contao_dc.tl_page', $attribute);
        self::assertInstanceOf(UpdateAction::class, $action);
        self::assertSame($row, $action->getCurrent());
        self::assertSame(['title' => 'Neu'], $action->getNew());
    }

    public function testEachOperationAsksWithItsAction(): void
    {
        $access = $this->access(['tl_news' => [7 => ['id' => 7, 'pid' => 2]]]);
        $access->assertRead('tl_news', 7);
        $access->assertDelete('tl_news', 7);
        $access->assertUpdate('tl_news', 7);
        $access->assertCreate('tl_news', ['pid' => 2]);

        self::assertInstanceOf(ReadAction::class, $this->asked[0][1]);
        self::assertInstanceOf(DeleteAction::class, $this->asked[1][1]);
        self::assertInstanceOf(UpdateAction::class, $this->asked[2][1]);
        self::assertNull($this->asked[2][1]->getNew(), 'no values: the edit right, like opening the form');
        self::assertInstanceOf(CreateAction::class, $this->asked[3][1]);
        self::assertSame(['pid' => 2], $this->asked[3][1]->getNew());
    }

    public function testAMissingRecordIsRefusedWithoutAskingTheVoters(): void
    {
        try {
            $this->access([])->assertUpdate('tl_page', 99);
            self::fail('expected a refusal');
        } catch (ToolRefusedException $e) {
            self::assertSame('Seite 99 nicht gefunden.', $e->getMessage());
        }
        self::assertSame([], $this->asked);
    }

    public function testADenialNamesTheRecordAndTheRightsThatMatter(): void
    {
        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('Contao erlaubt Ihnen nicht, Seite 5 zu ändern. Maßgeblich sind die Rechte Ihrer Benutzergruppe: Module, Seitenfreigaben (Pagemounts), Seitenrechte und erlaubte Seitentypen.');

        $this->access(['tl_page' => [5 => ['id' => 5]]], false)->assertUpdate('tl_page', 5, ['title' => 'x']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function newObjects(): iterable
    {
        yield 'page'    => ['tl_page', 'an dieser Stelle eine Seite anzulegen'];
        yield 'article' => ['tl_article', 'an dieser Stelle einen Artikel anzulegen'];
        yield 'content' => ['tl_content', 'an dieser Stelle ein Inhaltselement anzulegen'];
        yield 'archive' => ['tl_news_archive', 'an dieser Stelle ein News-Archiv anzulegen'];
    }

    /**
     * @dataProvider newObjects
     */
    public function testACreateDenialReadsAsGerman(string $table, string $phrase): void
    {
        self::assertStringContainsString($phrase, DataContainerAccess::deniedMessage(new CreateAction($table, []), null));
    }

    public function testDenialReasonIsNullWhenGranted(): void
    {
        self::assertNull($this->access([])->denialReason(new CreateAction('tl_faq', ['pid' => 1]), null));
    }

    public function testATableNameOutsideTheContaoPatternIsRefused(): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->access([])->fetchRecord('tl_page; DROP TABLE tl_user', 1);
    }

    public function testATableThatDoesNotExistHasNoRecord(): void
    {
        self::assertNull($this->access([])->fetchRecord('tl_missing', 1));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, array<int, array<string, mixed>>>, bool}>
     */
    public static function contentParents(): iterable
    {
        yield 'article'                  => [['ptable' => 'tl_article', 'pid' => 4], [], true];
        yield 'empty ptable is article'  => [['ptable' => '', 'pid' => 4], [], true];
        yield 'news'                     => [['ptable' => 'tl_news', 'pid' => 4], [], true];
        yield 'event'                    => [['ptable' => 'tl_calendar_events', 'pid' => 4], [], true];
        yield 'nested below an article'  => [['ptable' => 'tl_content', 'pid' => 8], ['tl_content' => [8 => ['id' => 8, 'ptable' => 'tl_article', 'pid' => 4]]], true];
        yield 'another table'            => [['ptable' => 'tl_whatever', 'pid' => 4], [], false];
        yield 'nested below another one' => [['ptable' => 'tl_content', 'pid' => 8], ['tl_content' => [8 => ['id' => 8, 'ptable' => 'tl_form', 'pid' => 4]]], false];
        yield 'nested, parent missing'   => [['ptable' => 'tl_content', 'pid' => 8], [], false];
    }

    /**
     * The content voters let any parent table pass that they do not know; the chat only
     * accepts those they judge.
     *
     * @dataProvider contentParents
     *
     * @param array<string, mixed>                              $record
     * @param array<string, array<int, array<string, mixed>>> $rows
     */
    public function testContentOnlyBelowAParentContaoJudges(array $record, array $rows, bool $allowed): void
    {
        $denial = $this->access($rows)->contentParentDenial($record);

        $allowed ? self::assertNull($denial) : self::assertNotNull($denial);
    }
}
