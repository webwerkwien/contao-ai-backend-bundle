<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Tests\Unit\Tool;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolExecutionException;
use Webwerkwien\ContaoAiBackendBundle\Exception\ToolRefusedException;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Tool\AbstractCoreCommandTool;

/**
 * What runCommand() makes of a core command's error answer (v0.12.0).
 *
 * core-bundle v1.3.0 marks a defect with `exception` — on the answer, or on an
 * entry of a bulk update's `errors`. That is tool_failed with a report; a refusal
 * stays tool_refused. A bulk update answers `status: partial` without a `message`,
 * which used to read "unbekannter Fehler" and drop every reason (review H4).
 */
class CoreAnswerKindTest extends TestCase
{
    /**
     * @param array<string, mixed> $answer
     */
    private function answer(array $answer, int $exit = Command::FAILURE): string
    {
        $command = new class ($answer, $exit) extends Command {
            /** @param array<string, mixed> $answer */
            public function __construct(private readonly array $answer, private readonly int $exit)
            {
                parent::__construct('probe:answer');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln((string) json_encode($this->answer));

                return $this->exit;
            }
        };

        $tool = new class ($this->createMock(ToolAccessChecker::class), $this->createStub(TokenChecker::class)) extends AbstractCoreCommandTool {
            public function getToolName(): string
            {
                return 'probe';
            }

            public function call(Command $command): string
            {
                return $this->runCommand($command, [], 'probe');
            }
        };
        $tool->setActingUserOverride($this->createMock(BackendUser::class));

        return $tool->call($command);
    }

    public function testARefusalStaysARefusalWithItsMessage(): void
    {
        $this->expectException(ToolRefusedException::class);
        $this->expectExceptionMessage('Tool "probe" abgelehnt: InvalidArgumentException: A website root belongs at the top level.');

        $this->answer(['status' => 'error', 'message' => 'InvalidArgumentException: A website root belongs at the top level.', 'code' => 1]);
    }

    public function testADefectIsAFailedToolWithAReport(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('DriverException: An exception occurred');

        $this->answer(['status' => 'error', 'message' => 'DriverException: An exception occurred', 'code' => 1, 'exception' => 'DriverException']);
    }

    public function testAnOlderCoreWithoutTheFieldStaysARefusal(): void
    {
        $this->expectException(ToolRefusedException::class);

        $this->answer(['status' => 'error', 'message' => 'TypeError: Argument #1 must be of type int', 'code' => 1]);
    }

    public function testAPartialBulkUpdateNamesEveryReason(): void
    {
        try {
            $this->answer(['status' => 'partial', 'total' => 3, 'succeeded' => 1, 'failed' => 2, 'ids' => [5], 'errors' => [
                ['id' => 7, 'message' => 'Page not found: 7'],
                ['id' => 9, 'message' => 'A website root belongs at the top level.'],
            ]]);
            self::fail('expected a refusal');
        } catch (ToolRefusedException $e) {
            self::assertStringNotContainsString('unbekannter Fehler', $e->getMessage());
            self::assertStringContainsString('2 von 3 nicht geändert', $e->getMessage());
            self::assertStringContainsString('7: Page not found: 7', $e->getMessage());
            self::assertStringContainsString('9: A website root belongs at the top level.', $e->getMessage());
        }
    }

    public function testAtMostFiveReasonsAreNamed(): void
    {
        $errors = array_map(static fn (int $id): array => ['id' => $id, 'message' => "Page not found: $id"], range(1, 7));

        $message = AbstractCoreCommandTool::failureMessage(['status' => 'partial', 'total' => 7, 'failed' => 7, 'errors' => $errors]);

        self::assertStringContainsString('5: Page not found: 5', $message);
        self::assertStringNotContainsString('6: Page not found', $message);
        self::assertStringEndsWith('… und 2 weitere', $message);
    }

    public function testAPartialBulkUpdateWithACrashedRecordIsADefect(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionMessage('8: Argument #1 must be of type int');

        $this->answer(['status' => 'partial', 'total' => 2, 'succeeded' => 1, 'failed' => 1, 'ids' => [5], 'errors' => [
            ['id' => 8, 'message' => 'Argument #1 must be of type int', 'exception' => 'TypeError'],
        ]]);
    }
}
