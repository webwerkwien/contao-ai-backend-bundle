<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the old chat address, `/contao?do=ai_chat`, to the chat page.
 *
 * Until 0.10.0 the chat was a legacy back-end module, and that was its address —
 * in bookmarks, and in Contao's own favourites, which store the URL. The
 * `BE_MOD` entry still exists (it carries the "Allowed modules" checkbox), but
 * without a callback Contao would answer that address with an empty module page.
 *
 * Priority 16: after Symfony's router (32), so `_route` is known, and before the
 * firewall (8). Redirecting an unauthenticated request is harmless — the target
 * sits behind the same back-end firewall and asks for a login itself.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 16)]
class LegacyChatUrlListener
{
    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('contao_backend' !== $request->attributes->get('_route') || 'ai_chat' !== $request->query->get('do')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('contao_ai_backend_chat')));
    }
}
