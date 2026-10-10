<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Hands the model's own mistakes back to the model instead of ending the turn.
 *
 * Two of them, and only these: arguments that do not fit the tool (a missing
 * parameter, a string where an int belongs — symfony/ai's
 * `InvalidToolCallArgumentsException`), and a tool name no tool has. Both used
 * to end the chat as `agent_failed` with a bug report, although nothing was
 * broken: the model can read the answer and call again. The run stays bounded
 * by the agent's maxToolCalls (50).
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
            return new ToolResult($toolCall, \sprintf(
                'The call was not executed: %s Check the tool\'s parameters and call it again.',
                rtrim($e->getMessage(), '.') . '.',
            ));
        } catch (ToolNotFoundException $e) {
            // The same exception reports a registered tool whose object is missing
            // (Toolbox::getExecutable) — a defect, not the model's mistake.
            $registered = array_map(static fn ($tool): string => $tool->getName(), $this->inner->getTools());
            if (\in_array($toolCall->getName(), $registered, true)) {
                throw $e;
            }

            return new ToolResult($toolCall, \sprintf(
                'There is no tool "%s". The tools you can call: %s.',
                $toolCall->getName(),
                implode(', ', $this->allowedToolNames),
            ));
        }
    }
}
