<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Symfony\AI\Agent\Agent;

final readonly class AgentInvocation
{
    /**
     * @param list<string> $allowedToolNames Per-tool-name filter applied via
     *   AgentProcessor's `tools` option. The Toolbox itself receives all tool
     *   classes the user can use (class-level isAccessibleBy), but admin-only
     *   sub-tools must not appear in the JSON-schema sent to the LLM — only
     *   the names returned here are advertised. Without this filter, Claude
     *   would see e.g. `news_delete` for a non-admin editor, attempt to call
     *   it, and the runtime denial would still protect us but waste a tool
     *   roundtrip and confuse the user.
     */
    public function __construct(
        public Agent $agent,
        public string $systemPrompt,
        public string $model,
        public array $allowedToolNames,
    ) {
    }
}
