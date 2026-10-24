<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Exception;

/**
 * A file the chat does not take, named for the editor (v1.0.0).
 *
 * Carries a key of contao_ai_chat (`ai_chat.<key>`) and its sprintf parameters;
 * the controller translates it into the user's language. An answer, not a
 * defect: the chat shows it as `attachment_refused`, without a report.
 */
final class AttachmentRefusedException extends \RuntimeException
{
    /**
     * @param list<scalar> $parameters
     */
    public function __construct(
        public readonly string $labelKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($labelKey);
    }
}
