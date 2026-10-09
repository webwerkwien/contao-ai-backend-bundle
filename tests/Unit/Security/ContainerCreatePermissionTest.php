<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Security;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\RecordPermissionChecker;

/**
 * Creating a news archive, calendar or FAQ category is decided by the `cud` voter alone.
 *
 * Until v0.10.0 a denial by the voter fell back to the user's `newp`/`calp`/`faqp`
 * arrays — the permission fields of Contao 5.3 to 5.6. Since v0.10.0 this bundle
 * requires Contao 5.7, where a migration removed those columns, so the fallback
 * could never decide anything (review 2026-10-03). Worse, it was a second door: had a
 * stale value reached the user object, it would have allowed what the voter refused.
 */
class ContainerCreatePermissionTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     */
    private function user(array $fields): BackendUser
    {
        return new class($fields) extends BackendUser {
            /** @param array<string, mixed> $fields */
            public function __construct(private readonly array $fields)
            {
            }

            public function __get($strKey)
            {
                return $this->fields[$strKey] ?? null;
            }

            public function __isset($strKey)
            {
                return isset($this->fields[$strKey]);
            }
        };
    }

    private function checker(bool $granted): RecordPermissionChecker
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($granted);

        return new RecordPermissionChecker($this->createMock(ContaoFramework::class), $auth);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function containers(): array
    {
        return [
            'news archive' => ['tl_news_archive', 'newp'],
            'calendar'     => ['tl_calendar', 'calp'],
            'faq category' => ['tl_faq_category', 'faqp'],
        ];
    }

    /**
     * @dataProvider containers
     */
    public function testTheVoterAllows(string $table, string $legacy): void
    {
        $this->checker(true)->assertCanCreateContainer($this->user(['isAdmin' => false]), $table, 1);

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider containers
     */
    public function testALegacyFieldNoLongerOverridesTheVoter(string $table, string $legacy): void
    {
        $this->expectException(ToolAccessDeniedException::class);

        $this->checker(false)->assertCanCreateContainer($this->user(['isAdmin' => false, $legacy => ['create']]), $table, 1);
    }

    public function testTheDenialNamesOnlyTheVoterPermission(): void
    {
        try {
            $this->checker(false)->assertCanCreateContainer($this->user(['isAdmin' => false]), 'tl_calendar', 1);
            self::fail('expected a denial');
        } catch (ToolAccessDeniedException $e) {
            self::assertStringContainsString('tl_calendar::create', $e->getMessage());
            self::assertStringNotContainsString('calp', $e->getMessage());
        }
    }
}
