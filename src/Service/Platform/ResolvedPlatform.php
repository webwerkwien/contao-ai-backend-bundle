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
    public function __construct(
        public readonly PlatformInterface $platform,
        public readonly string $model,
        public readonly PlatformDescriptor $descriptor,
    ) {
    }
}
