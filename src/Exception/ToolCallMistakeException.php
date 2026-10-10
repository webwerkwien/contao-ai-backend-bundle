<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Exception;

/**
 * The model called a tool wrongly in a way only the tool itself can tell: a command
 * name, an argument or an option that does not exist for this user.
 *
 * symfony/ai recognises a wrong parameter of the tool method; it cannot recognise a
 * wrong value inside one — `ext_run`'s `command` is a string either way. The chat
 * test on c5 (2026-10-10) showed the gap: the model invented `product:list`, the
 * refusal ended the turn, and the user saw a refusal for a question that had an
 * answer. {@see \Webwerkwien\ContaoAiBackendBundle\Service\SelfCorrectingToolbox}
 * hands this one back to the model instead, as it does with wrong parameters.
 *
 * A refusal everywhere else (the CLI bridge answers 422), which is why it extends
 * ToolRefusedException.
 */
class ToolCallMistakeException extends ToolRefusedException
{
}
