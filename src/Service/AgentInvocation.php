<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\AI\Agent\Agent;

final readonly class AgentInvocation
{
    public function __construct(
        public Agent $agent,
        public string $systemPrompt,
        public string $model,
    ) {
    }
}
