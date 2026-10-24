<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service\Platform;

use Symfony\AI\Platform\PlatformInterface;

/**
 * A built platform together with the model and the descriptor it came from.
 *
 * The descriptor travels along on purpose: callers keep needing to know *which*
 * provider this is and what it requires — the chat view for its message, the
 * rewrite tool for its error text. Handing back only the platform is what let
 * those call sites grow their own answers in the first place.
 */
final class ResolvedPlatform
{
    /**
     * Output tokens a Claude answer may take (v0.23.0). symfony/ai gives Claude 1000
     * unless told otherwise: answers stopped there, and a tool call with a long text
     * (a content element's HTML) was cut — with half its arguments up to 0.14, as
     * `MaxOutputTokensException` since 0.15. Only what is generated is billed.
     */
    public const ANTHROPIC_OUTPUT_TOKENS = 8192;

    public function __construct(
        public readonly PlatformInterface $platform,
        public readonly string $model,
        public readonly PlatformDescriptor $descriptor,
    ) {
    }

    /**
     * The model name a run is started with: for Anthropic with the output budget as
     * a model option, which the catalog reads from the name in 0.14 and 0.15 alike.
     * A name that already carries options is left as it is.
     */
    public function modelForRun(): string
    {
        if ('anthropic' !== $this->descriptor->key || str_contains($this->model, '?')) {
            return $this->model;
        }

        return $this->model . '?max_tokens=' . self::ANTHROPIC_OUTPUT_TOKENS;
    }
}
