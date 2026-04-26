<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Webwerkwien\ContaoAiBackendBundle\Security\AiAccessVoter;
use Webwerkwien\ContaoAiBackendBundle\Service\ChatViewRenderer;

class AiChatController extends AbstractController
{
    public function __construct(
        private readonly TokenChecker $tokenChecker,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ChatViewRenderer $renderer,
    ) {
    }

    #[Route('/contao/ai-chat', name: 'contao_ai_backend_chat', defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function index(): Response
    {
        $user = $this->requireBackendUser();

        if (!$this->authorizationChecker->isGranted(AiAccessVoter::ATTR_USE_CHAT)) {
            throw new AccessDeniedException('Backend module ai_chat is not granted.');
        }

        return new Response($this->renderer->render($user));
    }

    private function requireBackendUser(): BackendUser
    {
        if (null === $this->tokenChecker->getBackendUsername()) {
            throw new AccessDeniedException('No backend session.');
        }
        $user = BackendUser::getInstance();
        if (!$user instanceof BackendUser) {
            throw new AccessDeniedException('Invalid backend user.');
        }
        return $user;
    }
}
