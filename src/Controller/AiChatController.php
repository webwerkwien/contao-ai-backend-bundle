<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiBackendBundle\Security\AiAccessVoter;
use Webwerkwien\ContaoAiBackendBundle\Service\ChatViewContext;

/**
 * The AI chat page in the back end.
 *
 * 🔴 Issue #26 (Contao 6.0.0, v0.9.3): *Template "@Contao/be_ai_chat.html.twig"
 * is not defined.* Until 0.10.0 the page was a legacy `BackendModule` reached
 * through `BE_MOD['callback']`, wrapped in a `be_ai_chat.html5` template.
 * Contao 6 renders legacy modules through Twig only and never reads `.html5`,
 * so the chat could not open. The page is a Symfony route now, rendered by
 * Contao's own controller base for back-end pages, and nothing legacy is left
 * between the menu and the template.
 *
 * The menu entry comes from BackendMenuListener. `BE_MOD` still carries an
 * `ai_chat` entry, hidden from the navigation, for one reason: it is what puts
 * "AI chat" under "Allowed modules", which AiAccessVoter reads.
 *
 * ⚠️ `Contao\CoreBundle\Controller\AbstractBackendController` is deprecated
 * since Contao 5.7 in favour of `…\Controller\Backend\AbstractBackendController`.
 * The new one does not exist in 5.3, which this bundle supports, so the old
 * one stays until 5.3 is dropped. Both work up to and including 6.x.
 */
class AiChatController extends AbstractBackendController
{
    public function __construct(
        private readonly TokenChecker $tokenChecker,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ChatViewContext $chatContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('%contao.backend.route_prefix%/ai-chat', name: 'contao_ai_backend_chat', defaults: ['_scope' => 'backend', '_token_check' => true])]
    public function index(): Response
    {
        $user = $this->requireBackendUser();

        if (!$this->authorizationChecker->isGranted(AiAccessVoter::ATTR_USE_CHAT)) {
            throw new AccessDeniedException('Backend module ai_chat is not granted.');
        }

        $label = $this->translator->trans('MOD.ai_chat.0', [], 'contao_modules');

        return $this->render('@Contao/backend/ai_chat.html.twig', [
            'title'    => $label,
            'headline' => $label,
            ...$this->chatContext->forUser($user),
        ]);
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
