<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Dca;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiCliTokenController;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

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
        private readonly RequestStack $requestStack,
        private readonly string $csrfTokenName,
        private readonly PlatformRegistry $platforms,
    ) {
    }

    /**
     * options_callback for tl_user.ai_platform.
     *
     * The list used to be a literal `['anthropic', 'openai']` in the DCA. It is
     * now whatever `symfony/ai-*-platform` packages are installed, read from
     * each bridge's own factory signature. `composer require
     * symfony/ai-mistral-platform` puts Mistral in this select with no code
     * change here.
     *
     * @return array<string, string>
     */
    public function platformOptions(): array
    {
        return $this->platforms->options();
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

        // Phase 10.2-UX: read + consume the one-shot cleartext (set by the
        // controller right before redirecting back). Single render, then
        // gone — even an F5 reload doesn't re-show it.
        $oneShotToken = '';
        $session = $this->requestStack->getCurrentRequest()?->getSession();
        if ($session) {
            $oneShotKey = AiCliTokenController::ONE_SHOT_KEY_PREFIX . $userId;
            $oneShotToken = (string) $session->get($oneShotKey, '');
            if ('' !== $oneShotToken) {
                $session->remove($oneShotKey);
            }
        }

        // The outer DCA <form> already carries a REQUEST_TOKEN. We render
        // <button formaction="…"> instead of nested <form>s (HTML5 forbids
        // nesting forms; browsers silently drop our inner forms and submit
        // the outer DCA form to its own URL — exactly what we observed in
        // the access log). The button submits the outer form to our route,
        // and Contao's REQUEST_TOKEN field rides along automatically.
        $rotateUrl  = $this->urlGenerator->generate('contao_ai_backend_cli_token_rotate', ['userId' => $userId]);
        $clearUrl   = $this->urlGenerator->generate('contao_ai_backend_cli_token_clear',  ['userId' => $userId]);

        $label        = $this->t('tl_user.ai_cli_token.0',         'CLI-Bridge-Token');
        $description  = $this->t('tl_user.ai_cli_token.1',         'Bearer-Token für den Python-CLI-Agent. Nach Klick auf „Generieren" wird der Klartext einmalig hier angezeigt — bitte sofort kopieren, danach ist nur der Hash in der DB.');
        $statusLabel  = $hasToken
            ? $this->t('tl_user.ai_cli_token_status_set',   'Token gesetzt (Hash gespeichert)')
            : $this->t('tl_user.ai_cli_token_status_empty', 'Kein Token gesetzt');
        $rotateLabel  = $this->t('tl_user.ai_cli_token_rotate',    $hasToken ? 'Neu generieren' : 'Generieren');
        $clearLabel   = $this->t('tl_user.ai_cli_token_clear',     'Löschen');
        $copyLabel    = $this->t('tl_user.ai_cli_token_copy',      'Token kopieren');
        $copiedLabel  = $this->t('tl_user.ai_cli_token_copied',    'Kopiert!');
        $confirmLabel = $this->t('tl_user.ai_cli_token_confirm',   'Token wirklich löschen? Der CLI-Agent kann sich danach nicht mehr authentifizieren.');

        $statusBadge = \sprintf(
            '<span style="display:inline-block;margin-left:1em;padding:.15em .6em;border-radius:.25em;background:%s;color:#fff;font-size:.85em;font-weight:normal;vertical-align:middle;">%s</span>',
            $hasToken ? '#0a0' : '#999',
            $this->h($statusLabel),
        );

        // One-shot token block — only rendered immediately after rotate.
        // The "copy" button uses navigator.clipboard.writeText() which works
        // in HTTPS contexts (the backend always is). Inline onclick because
        // adding a separate <script> file would need asset registration for
        // a 5-line interaction — disproportionate.
        //
        // Subtle bug we fixed: json_encode() produces strings like "Kopiert!"
        // (with literal " quotes). Embedded raw into onclick="..." those
        // quotes terminate the HTML attribute and the JS leaks as button
        // text. So we json_encode → htmlspecialchars(ENT_QUOTES) — the "
        // becomes &quot; in HTML, the browser decodes it back to " before
        // handing the value to the JS parser, and inside JS we see the
        // proper string literal.
        $oneShotBlock = '';
        if ('' !== $oneShotToken) {
            $copiedJs = $this->h(json_encode($copiedLabel, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
            $copyJs   = $this->h(json_encode($copyLabel,   JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
            $oneShotBlock = \sprintf(
                '<div style="margin:1em 0;padding:.8em 1em;background:#fff8d8;border:1px solid #d8c060;border-radius:.25em;">'
                . '<strong style="display:block;margin-bottom:.6em;color:#806000;">%s</strong>'
                . '<div style="display:flex;gap:1em;align-items:center;">'
                .   '<code id="ai_cli_token_oneshot" style="flex:1;padding:.4em .6em;background:#fff;border:1px solid #ccc;border-radius:.2em;font-family:monospace;word-break:break-all;user-select:all;">%s</code>'
                .   '<button type="button" class="tl_submit" style="white-space:nowrap;" '
                .     'onclick="var b=this;navigator.clipboard.writeText(document.getElementById(&#039;ai_cli_token_oneshot&#039;).textContent).then(function(){b.textContent=%s;setTimeout(function(){b.textContent=%s;},2000);});">%s</button>'
                . '</div>'
                . '</div>',
                $this->h($this->t('tl_user.ai_cli_token_oneshot_warning', 'Klartext-Token (NUR JETZT sichtbar — bitte kopieren):')),
                $this->h($oneShotToken),
                $copiedJs,
                $copyJs,
                $this->h($copyLabel),
            );
        }

        $clearBtn = $hasToken
            ? \sprintf(
                '<button type="submit" formaction="%s" formmethod="post" formnovalidate class="tl_submit" style="margin-left:1.5em;" onclick="return confirm(\'%s\');">%s</button>',
                $this->h($clearUrl),
                $this->h($confirmLabel),
                $this->h($clearLabel),
            )
            : '';

        return \sprintf(
            '<div class="widget w50 clr">'
            . '<h3 style="margin-bottom:.8em;"><label>%s</label>%s</h3>'
            . '%s'
            . '<div style="margin-top:1.2em;">'
            .   '<button type="submit" formaction="%s" formmethod="post" formnovalidate class="tl_submit">%s</button>'
            .   '%s'
            . '</div>'
            . '<p class="tl_help tl_tip" style="margin-top:1.2em;">%s</p>'
            . '</div>',
            $this->h($label),
            $statusBadge,
            $oneShotBlock,
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
