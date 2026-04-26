<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Service;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoAiBackendBundle\Security\ToolAccessChecker;

class ChatViewRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly UserAiConfig $userConfig,
        private readonly ToolAccessChecker $toolAccess,
    ) {
    }

    public function render(BackendUser $user): string
    {
        $config = $this->userConfig->getForUser($user);

        return $this->twig->render('@ContaoAiBackend/Backend/chat.html.twig', [
            'hasKey'    => $config->hasApiKey(),
            'platform'  => $config->platform,
            'tools'     => $this->toolAccess->listAllowedTools($user),
            'csrfToken' => $this->csrf->getDefaultTokenValue(),
            'streamUrl' => $this->router->generate('contao_ai_backend_stream'),
        ]);
    }
}
