<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiChatController;
use Webwerkwien\ContaoAiBackendBundle\Security\AiAccessVoter;

/**
 * Puts "AI chat" into the "System" group of the back-end menu.
 *
 * The way Contao adds its own route-based pages (`BackendTemplateStudioListener`
 * in 6.0) and the way its guide for back-end routes describes it. The legacy
 * `BE_MOD` entry is hidden from the navigation, so this is the only menu entry.
 *
 * Priority -255, as in Contao's guide: the "system" node is created by Contao's
 * own menu listener, and this one has to run after it or there is nothing to add
 * the entry to.
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ('mainMenu' !== $tree->getName()) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(AiAccessVoter::ATTR_USE_CHAT)) {
            return;
        }

        $systemNode = $tree->getChild('system');

        if (null === $systemNode) {
            return;
        }

        $controller = $this->requestStack->getCurrentRequest()?->attributes->get('_controller');

        $node = $event->getFactory()
            ->createItem('ai_chat')
            ->setLabel($this->translator->trans('MOD.ai_chat.0', [], 'contao_modules'))
            ->setUri($this->router->generate('contao_ai_backend_chat'))
            ->setLinkAttribute('class', 'navigation ai_chat')
            ->setLinkAttribute('title', $this->translator->trans('MOD.ai_chat.1', [], 'contao_modules'))
            ->setLinkAttribute('data-contao--tooltips-target', 'tooltip')
            // The label is translated above; without this Contao would run it
            // through the translator a second time.
            ->setExtra('translation_domain', false)
            ->setCurrent(\is_string($controller) && str_starts_with($controller, AiChatController::class))
        ;

        $systemNode->addChild($node);
    }
}
