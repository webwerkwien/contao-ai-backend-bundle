<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Dca;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase 10.2: DCA input_field_callback für tl_user.ai_cli_token.
 *
 * Ersetzt das normale Eingabefeld komplett: zeigt Status (vorhanden/leer)
 * und zwei POST-Forms (Generate/Rotate, Clear). Klartext-Token wird NIE in
 * diesem Widget gerendert — er erscheint nur einmalig nach erfolgreichem
 * Rotate via Flash-Bag in der BE.info-Box.
 */
class TlUserCallback
{
    public function __construct(
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly Connection $connection,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * Renders the full DCA row for ai_cli_token. Signature matches Contao's
     * input_field_callback contract: (DataContainer $dc, string $xlabel = '').
     */
    public function tokenWidget(DataContainer $dc): string
    {
        $userId = (int) $dc->id;
        if ($userId <= 0) {
            return '';
        }

        $hash = (string) $this->connection->fetchOne(
            'SELECT ai_cli_token FROM tl_user WHERE id = :id',
            ['id' => $userId],
        );
        $hasToken = '' !== $hash;

        // The outer DCA <form> already carries a REQUEST_TOKEN. We render
        // <button formaction="…"> instead of nested <form>s (HTML5 forbids
        // nesting forms; browsers silently drop our inner forms and submit
        // the outer DCA form to its own URL — exactly what we observed in
        // the access log). The button submits the outer form to our route,
        // and Contao's REQUEST_TOKEN field rides along automatically.
        $rotateUrl  = $this->urlGenerator->generate('contao_ai_backend_cli_token_rotate', ['userId' => $userId]);
        $clearUrl   = $this->urlGenerator->generate('contao_ai_backend_cli_token_clear',  ['userId' => $userId]);

        $label        = $this->t('tl_user.ai_cli_token.0',         'CLI-Bridge-Token');
        $description  = $this->t('tl_user.ai_cli_token.1',         'Bearer-Token für den Python-CLI-Agent. Nach Klick auf „Generieren" wird der Klartext einmalig oben angezeigt — bitte kopieren, danach ist nur der Hash in der DB.');
        $statusLabel  = $hasToken
            ? $this->t('tl_user.ai_cli_token_status_set',   'Token gesetzt (Hash gespeichert)')
            : $this->t('tl_user.ai_cli_token_status_empty', 'Kein Token gesetzt');
        $rotateLabel  = $this->t('tl_user.ai_cli_token_rotate',    $hasToken ? 'Neu generieren' : 'Generieren');
        $clearLabel   = $this->t('tl_user.ai_cli_token_clear',     'Löschen');
        $confirmLabel = $this->t('tl_user.ai_cli_token_confirm',   'Token wirklich löschen? Der CLI-Agent kann sich danach nicht mehr authentifizieren.');

        $clearBtn = $hasToken
            ? \sprintf(
                '<button type="submit" formaction="%s" formmethod="post" formnovalidate class="tl_submit" onclick="return confirm(\'%s\');">%s</button>',
                $this->h($clearUrl),
                $this->h($confirmLabel),
                $this->h($clearLabel),
            )
            : '';

        return \sprintf(
            '<div class="widget w50 clr">'
            . '<h3><label>%s</label></h3>'
            . '<p style="margin:.2em 0;color:%s;font-weight:bold;">%s</p>'
            . '<div style="display:flex;gap:.5em;margin-top:.4em;">'
            .   '<button type="submit" formaction="%s" formmethod="post" formnovalidate class="tl_submit">%s</button>'
            .   '%s'
            . '</div>'
            . '<p class="tl_help tl_tip" style="margin-top:.4em;">%s</p>'
            . '</div>',
            $this->h($label),
            $hasToken ? '#0a0' : '#999',
            $this->h($statusLabel),
            $this->h($rotateUrl),
            $this->h($rotateLabel),
            $clearBtn,
            $this->h($description),
        );
    }

    private function t(string $key, string $fallback): string
    {
        // Contao language strings live in $GLOBALS['TL_LANG'] — translator domain
        // 'contao_tl_user' is the canonical hookup. Fallback returns the German
        // string if the key is missing (e.g. en/tl_user.php not yet loaded).
        $translated = $this->translator->trans($key, [], 'contao_tl_user');
        return $translated === $key ? $fallback : $translated;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
