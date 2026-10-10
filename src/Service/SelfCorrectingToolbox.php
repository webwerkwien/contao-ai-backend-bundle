<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException as ToolboxExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Hands the model's own mistakes back to the model instead of ending the turn.
 *
 * Two of them, and only these: arguments that do not fit the tool, and a tool
 * name no tool has. Arguments arrive in two forms — symfony/ai's
 * `InvalidToolCallArgumentsException` (a missing parameter, a string where an
 * int belongs), and, for an `array` parameter its denormalizer does not check,
 * PHP's TypeError at the call of the tool method (`fields` as a string). Both
 * used to end the chat as `agent_failed` with a bug report, although nothing
 * was broken: the model can read the answer and call again. The run stays
 * bounded by the agent's maxToolCalls (50).
 *
 * Deliberately narrower than symfony/ai's own FaultTolerantToolbox:
 *
 * - **A refusal, a denied permission and a crash still end the turn.** They come
 *   from our tools and belong to the user (`tool_refused`, `access_denied`,
 *   `tool_failed` with a report) — handed to the model, a "Seite 9 nicht gefunden"
 *   would be smoothed over in prose and a defect never reported.
 * - **The list of names is the user's**, not every registered tool:
 *   FaultTolerantToolbox lists the whole toolbox, admin-only sub-tools included.
 * - **A registered tool the run left out never gets here.** The Runner refuses it
 *   before the toolbox runs (#2602), and the chat answers `access_denied`.
 * - **A registered name stays an exception** — then the toolbox lost the tool's
 *   object, which is ours to fix.
 */
final class SelfCorrectingToolbox implements ToolboxInterface
{
    /**
     * @param list<string> $allowedToolNames the tools this user may call
     */
    public function __construct(
        private readonly ToolboxInterface $inner,
        private readonly array $allowedToolNames,
    ) {
    }

    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        try {
            return $this->inner->execute($toolCall);
        } catch (InvalidToolCallArgumentsException $e) {
            return new ToolResult($toolCall, self::tryAgain(rtrim($e->getMessage(), '.') . '.'));
        } catch (ToolboxExecutionException $e) {
            // symfony/ai checks only what its denormalizer understands: an `array`
            // parameter gets the model's value unchecked, and a string for `fields`
            // fails as a TypeError when the tool is called (measured on c5,
            // 2026-10-10, page_update). Only that call counts — a TypeError deeper
            // in our own code is a defect.
            $previous = $e->getPrevious();
            $metadata = $this->metadataFor($toolCall->getName());
            if (!$previous instanceof \TypeError || null === $metadata || !self::isArgumentTypeError($previous, $metadata)) {
                throw $e;
            }

            return new ToolResult($toolCall, self::tryAgain(self::describeArgumentTypeError($previous, $toolCall->getName())));
        } catch (ToolNotFoundException $e) {
            // The same exception reports a registered tool whose object is missing
            // (Toolbox::getExecutable) — a defect, not the model's mistake.
            if (null !== $this->metadataFor($toolCall->getName())) {
                throw $e;
            }

            return new ToolResult($toolCall, \sprintf(
                'There is no tool "%s". The tools you can call: %s.',
                $toolCall->getName(),
                [] === $this->allowedToolNames ? 'none' : implode(', ', $this->allowedToolNames),
            ));
        }
    }

    /**
     * Whether $e is PHP refusing the arguments of the tool's own method — the
     * model's value reached the call unchecked — rather than a TypeError inside it.
     *
     * For a userland call PHP throws in the callee's frame, so the top of the
     * trace is the tool method itself. A TypeError further down names another
     * function there.
     */
    public static function isArgumentTypeError(?\Throwable $e, Tool $metadata): bool
    {
        if (!$e instanceof \TypeError) {
            return false;
        }

        $top = $e->getTrace()[0] ?? null;
        if (null === $top) {
            return false;
        }

        return ($top['class'] ?? null) === $metadata->getReference()->getClass()
            && $top['function'] === $metadata->getReference()->getMethod()
            && 1 === preg_match(self::ARGUMENT_TYPE_ERROR, $e->getMessage());
    }

    private const ARGUMENT_TYPE_ERROR = '/Argument #\d+ \(\$(\w+)\) must be of type (.+?), (.+?) given/';

    /**
     * Our own sentence, not PHP's: that one names the class and the server path.
     */
    private static function describeArgumentTypeError(\TypeError $e, string $toolName): string
    {
        preg_match(self::ARGUMENT_TYPE_ERROR, $e->getMessage(), $m);

        return \sprintf('Invalid value for parameter "%s" of tool "%s": must be of type %s, %s given.', $m[1], $toolName, $m[2], $m[3]);
    }

    private static function tryAgain(string $reason): string
    {
        return \sprintf('The call was not executed: %s Check the tool\'s parameters and call it again.', $reason);
    }

    private function metadataFor(string $name): ?Tool
    {
        foreach ($this->inner->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
