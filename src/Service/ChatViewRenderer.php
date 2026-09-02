<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformResolver;

class ChatViewRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly UserAiConfig $userConfig,
        private readonly ToolAccessChecker $toolAccess,
        private readonly PlatformResolver $platformResolver,
    ) {
    }

    public function render(BackendUser $user): string
    {
        $config = $this->userConfig->getForUser($user);

        // 🔴 H-2 (Fable review, 2026-09-02): this passed `hasKey =>
        // $config->hasApiKey()` and the template hid the entire chat when it was
        // false. A user on Ollama has no key and needs none — so the case the
        // derived registry was built for could not be reached through the UI at
        // all, while AgentFactory would happily have served it.
        //
        // The renderer no longer decides what "configured" means; it asks the
        // one place that knows, and shows the reason instead of a generic hint.
        $blocker = $this->platformResolver->missingRequirement($user);

        return $this->twig->render('@ContaoAiBackend/Backend/chat.html.twig', [
            'hasKey'    => null === $blocker,
            'blocker'   => $blocker,
            'platform'  => $config->platform,
            'tools'     => $this->toolAccess->listAllowedTools($user),
            'csrfToken' => $this->csrf->getDefaultTokenValue(),
            'streamUrl' => $this->router->generate('contao_ai_backend_stream'),
        ]);
    }
}
