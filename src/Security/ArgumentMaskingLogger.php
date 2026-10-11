<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * The logger handed to symfony/ai's Toolbox (v0.14.1). Its own debug line
 * `Executing tool "…"` carries the raw arguments as context; that line is silent with
 * Contao's default logging, but a handler that writes context — or a buffer flushed
 * after an error in the same request — would store a password from ext_run.
 */
final class ArgumentMaskingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, ToolArgumentMasker::mask($context));
    }
}
