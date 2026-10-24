<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolAccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\DataContainerAccess;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\PendingActionStore;
use Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Support\BuildsFieldPermission;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;
use Webwerkwien\ContaoAiBackendBundle\Tool\EventTool;
use Webwerkwien\ContaoAiCoreBundle\Command\EventCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\EventUpdateCommand;

/**
 * A day as 2026-11-27 and a time as 10:00 reach contao:event:update through its own
 * options, which store them as timestamps (v0.19.3). Through --set the core refused them:
 * the chat could not move an event or give it a time (chat runner, 2026-10-19).
 */
class EventTimesTest extends TestCase
{
    use BuildsFieldPermission;

    private const DATE = ['inputType' => 'text', 'sql' => 'int(10) unsigned NULL'];

    /** @var array<string, mixed>|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        $this->defineDca(['tl_calendar_events' => [
            'title' => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            'location' => ['inputType' => 'text', 'sql' => "varchar(255) NOT NULL default ''"],
            'startDate' => self::DATE, 'endDate' => self::DATE, 'startTime' => self::DATE, 'endTime' => self::DATE,
            'addTime' => ['inputType' => 'checkbox', 'sql' => ['type' => 'boolean', 'default' => false]],
        ]]);
        $this->sent = null;
    }

    protected function tearDown(): void
    {
        $this->restoreDca();
    }

    public function testADayAndATimeGoToTheOptions(): void
    {
        $this->send($this->tool(), ['startDate', '2026-11-27', 'startTime', '10:00', 'endTime', '16:00']);

        $this->assertSame('2026-11-27', $this->sent['--startDate']);
        $this->assertSame('10:00', $this->sent['--startTime']);
        $this->assertSame('16:00', $this->sent['--endTime']);
        $this->assertArrayNotHasKey('--endDate', $this->sent, 'an option not given is not passed');
        $this->assertSame([], $this->sent['--set']);
    }

    public function testOtherFieldsStayInSet(): void
    {
        $this->send($this->tool(), ['location' => 'Wien', 'endDate' => '2026-11-28']);

        $this->assertSame(['location=Wien'], $this->sent['--set']);
        $this->assertSame('2026-11-28', $this->sent['--endDate']);
    }

    /**
     * A timestamp is what the column stores and what --set has always taken.
     */
    public function testATimestampStaysInSet(): void
    {
        $this->send($this->tool(), ['startDate' => '1795734000']);

        $this->assertSame(['startDate=1795734000'], $this->sent['--set']);
        $this->assertArrayNotHasKey('--startDate', $this->sent);
    }

    /**
     * A time makes the core set addTime: that right is needed too, as through --set.
     */
    public function testATimeNeedsTheRightOnAddTime(): void
    {
        $tool = $this->tool(['tl_calendar_events::startTime']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_calendar_events.addTime');

        $this->send($tool, ['startTime' => '10:00']);
    }

    public function testADayNeedsNoRightOnAddTime(): void
    {
        $this->send($this->tool(['tl_calendar_events::startDate']), ['startDate' => '2026-11-27']);

        $this->assertSame('2026-11-27', $this->sent['--startDate']);
    }

    /**
     * Before core v0.28.0 the options do not exist: a plain refusal, not "option does
     * not exist" and a failure report.
     */
    public function testAnOlderCoreIsToldPlainly(): void
    {
        $tool = $this->tool(withOptions: false);

        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('ab v0.28.0');

        $this->send($tool, ['startDate' => '2026-11-27']);
    }

    public function testATimeNeedsItsFieldRight(): void
    {
        $tool = $this->tool(['tl_calendar_events::location']);

        $this->expectException(ToolAccessDeniedException::class);
        $this->expectExceptionMessage('tl_calendar_events.startTime');

        $this->send($tool, ['startTime' => '10:00']);
    }

    /**
     * @param array<int|string, mixed> $fields
     */
    private function send(AbstractCoreCommandTool $tool, array $fields): void
    {
        $this->assertTrue(method_exists($tool, 'update'));
        $tool->update(9, $fields);
    }

    /**
     * @param list<string> $granted
     */
    private function tool(array $granted = ['tl_calendar_events::location', 'tl_calendar_events::startDate', 'tl_calendar_events::endDate', 'tl_calendar_events::startTime', 'tl_calendar_events::endTime', 'tl_calendar_events::addTime'], bool $withOptions = true): AbstractCoreCommandTool
    {
        $update = $this->createMock(EventUpdateCommand::class);
        // what contao:event:update declares since core v0.28.0
        $update->method('getDefinition')->willReturn(new InputDefinition($withOptions ? array_map(
            static fn (string $name): InputOption => new InputOption($name, null, InputOption::VALUE_REQUIRED),
            ['set', 'startDate', 'endDate', 'startTime', 'endTime'],
        ) : []));
        $update->method('run')->willReturnCallback(function (InputInterface $input, OutputInterface $output): int {
            $this->sent = [];
            foreach (['--set', '--startDate', '--endDate', '--startTime', '--endTime'] as $option) {
                if ($input->hasParameterOption($option)) {
                    $this->sent[$option] = $input->getParameterOption($option);
                }
            }
            $output->write('{"status":"ok","id":9}');

            return 0;
        });

        $tool = new EventTool(
            $this->createMock(ToolAccessChecker::class),
            $this->createStub(TokenChecker::class),
            $this->createMock(ContaoFramework::class),
            $this->createMock(EventCreateCommand::class),
            $update,
            $this->createMock(EventDeleteCommand::class),
            $this->createMock(EventReadCommand::class),
        );

        $records = $this->createMock(DataContainerAccess::class);
        $records->method('assertUpdate')->willReturn(['id' => 9, 'pid' => 2, 'title' => 'Workshop']);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key) => match ($key) {
            'isAdmin'  => false,
            'username' => 'redakteur',
            default    => null,
        });

        $tool->setFieldPermission($this->fieldPermission($granted));
        $tool->setAuthorizationChecker($this->grantingAuthorization($granted));
        $tool->setPendingActionStore($this->createMock(PendingActionStore::class));
        $tool->setDataContainerAccess($records);
        $tool->setActingUserOverride($user);

        return $tool;
    }
}
