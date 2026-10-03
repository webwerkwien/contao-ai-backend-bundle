<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Controller\Backend\AbstractBackendController;
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
 * The base is `Contao\CoreBundle\Controller\Backend\AbstractBackendController`,
 * which exists from Contao 5.7 — the lowest version this bundle supports. The
 * older `Contao\CoreBundle\Controller\AbstractBackendController` is deprecated
 * since 5.7 and goes away in Contao 7.
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
        // render() builds Contao's BackendMain, which needs the framework. It is
        // already up on every authenticated back-end request (the user provider
        // starts it), but Contao's own BackendController asks for it in each
        // action rather than relying on that, and so does this one.
        $this->initializeContaoFramework();

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
