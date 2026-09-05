<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Phase 10.2: Backend-Profil-UI für tl_user.ai_cli_token. Generiert einen
 * neuen 64-char Hex-Token, hasht ihn (password_hash, PASSWORD_DEFAULT) und
 * speichert nur den Hash. Der Klartext-Token wird einmalig in der Flash-Bag
 * angezeigt — nach dem Refresh ist er weg, der User muss ihn kopieren.
 *
 * Auth: Backend-Session (Contao-Standard-Firewall). Aktion erlaubt für
 * (a) Admins auf jeden User, (b) jeden User auf seinen eigenen Datensatz.
 */
class AiCliTokenController extends AbstractController
{
    private const FLASH_TYPE = 'contao.BE.info';
    public  const ONE_SHOT_KEY_PREFIX = 'contao_ai_backend.cli_token_oneshot.';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenChecker $tokenChecker,
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $csrfTokenName,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/contao/ai-cli-token/rotate/{userId}',
        name: 'contao_ai_backend_cli_token_rotate',
        requirements: ['userId' => '\d+'],
        methods: ['POST'],
        defaults: ['_scope' => 'backend', '_token_check' => false],
    )]
    public function rotate(int $userId, Request $request): RedirectResponse
    {
        $this->framework->initialize();
        $this->assertCanModify($userId);
        $this->assertCsrf($request);

        $token = bin2hex(random_bytes(32));
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $this->connection->update('tl_user', ['ai_cli_token' => $hash], ['id' => $userId]);

        // One-shot stash: the widget reads this on the next render and clears it.
        // Lives in the session bag (NOT flash bag) because the flash bag is
        // consumed by Message::generate() before our widget runs.
        $request->getSession()->set(self::ONE_SHOT_KEY_PREFIX . $userId, $userId . '.' . $token);

        $this->flash($request, 'token_created');

        return new RedirectResponse($this->backUrl($userId));
    }

    #[Route(
        '/contao/ai-cli-token/clear/{userId}',
        name: 'contao_ai_backend_cli_token_clear',
        requirements: ['userId' => '\d+'],
        methods: ['POST'],
        defaults: ['_scope' => 'backend', '_token_check' => false],
    )]
    public function clear(int $userId, Request $request): RedirectResponse
    {
        $this->framework->initialize();
        $this->assertCanModify($userId);
        $this->assertCsrf($request);

        $this->connection->update('tl_user', ['ai_cli_token' => ''], ['id' => $userId]);

        $this->flash($request, 'token_cleared');

        return new RedirectResponse($this->backUrl($userId));
    }

    /**
     * Adds one translated back-end message.
     *
     * 🔴 Two things were wrong here until 2026-09-05, and PHPStan found only one
     * of them. The typed one: `getSession()` returns `SessionInterface`, which
     * has no `getFlashBag()` — it worked because Symfony happens to hand out a
     * `Session`, and would stop working the moment it does not.
     *
     * 🎯 The untyped one was the more consequential: both messages were
     * hardcoded German, and they are shown in the back end after generating or
     * clearing a token. No tool flags that. It surfaced only because fixing the
     * type error meant reading the lines.
     */
    private function flash(Request $request, string $key): void
    {
        $session = $request->getSession();

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(
            self::FLASH_TYPE,
            $this->translator->trans('ai_chat.' . $key, [], 'contao_ai_chat'),
        );
    }

    private function assertCanModify(int $userId): void
    {
        if (null === $this->tokenChecker->getBackendUsername()) {
            throw new AccessDeniedException('No backend session.');
        }
        $current = BackendUser::getInstance();
        if (!$current instanceof BackendUser) {
            throw new AccessDeniedException('Invalid backend user.');
        }
        if ($current->isAdmin) {
            return;
        }
        if ((int) $current->id === $userId) {
            return;
        }
        throw new AccessDeniedException('Token rotation requires admin or self.');
    }

    private function assertCsrf(Request $request): void
    {
        $token = (string) $request->request->get('REQUEST_TOKEN', '');
        if ('' === $token || !$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, $token))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }
    }

    private function backUrl(int $userId): string
    {
        return $this->urlGenerator->generate(
            'contao_backend',
            ['do' => 'user', 'act' => 'edit', 'id' => $userId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
