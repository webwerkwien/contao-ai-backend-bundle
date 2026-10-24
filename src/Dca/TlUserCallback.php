<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Dca;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoAiBackendBundle\Controller\AiCliTokenController;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\ModelChoice;
use Webwerkwien\ContaoAiBackendBundle\Service\Platform\PlatformRegistry;

/**
 * DCA input_field_callback für tl_user.ai_cli_token.
 *
 * Ersetzt das normale Eingabefeld komplett: zeigt Status (vorhanden/leer)
 * und zwei POST-Buttons (Generate/Rotate, Clear). Der Klartext-Token erscheint
 * genau einmal direkt nach dem Rotate in diesem Widget — aus der Session, die
 * AiCliTokenController dafür füllt und die hier beim Rendern geleert wird —,
 * seit v0.16.3 mit dem nächsten Schritt im Terminal darunter.
 */
class TlUserCallback
{
    /**
     * 🔴 `$csrf` und `$csrfTokenName` sind am 2026-09-05 entfallen — sie wurden
     * injiziert und nie gelesen. Übrig geblieben aus der Zeit, als hier eigene
     * `<form>`-Elemente mit eigenem Token gerendert wurden; seit dem Umbau auf
     * `<button formaction="…">` trägt die äußere DCA-Form Contaos `REQUEST_TOKEN`
     * (siehe {@see tokenWidget()}).
     *
     * 🎯 Der Schutz war nie weg — er sitzt in {@see AiCliTokenController}, das
     * `assertCsrf()` in beiden Routen erzwingt. Die tote Injektion war trotzdem
     * mehr als Ballast: Wer die Klasse liest, nimmt an, dass CSRF *hier*
     * gehandhabt wird, und sucht die Lücke an der falschen Stelle.
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly PlatformRegistry $platforms,
        private readonly ?ModelChoice $models = null,
    ) {
    }

    /**
     * load_callback for tl_user.ai_model (v0.20.0): an empty field shows, as placeholder,
     * the model that runs then — the installation's or the provider's default. Until then
     * an empty field said nothing, and an installation ran on claude-sonnet-4-5 without
     * anyone seeing it. The widget is built after the load callbacks from the same
     * DCA entry, so setting the placeholder here reaches it.
     */
    public function modelPlaceholder(mixed $value, DataContainer $dc): mixed
    {
        $platform = (string) ($dc->activeRecord->ai_platform ?? '');
        if ('' === $platform && $dc->id) {
            $platform = (string) $this->connection->fetchOne('SELECT ai_platform FROM tl_user WHERE id = ?', [(int) $dc->id]);
        }

        $default = '' === $platform ? null : $this->models?->defaultFor($platform);
        if (null !== $default) {
            $GLOBALS['TL_DCA']['tl_user']['fields']['ai_model']['eval']['placeholder'] = $default;
        }

        return $value;
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

        // Read + consume the one-shot cleartext (set by the
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
        $description  = $this->t('tl_user.ai_cli_token.1',         'Optional: nur für „bridge clone" und „bridge rewrite" von contao-ai-cli aus dem Terminal — der Chat braucht ihn nicht. Nach Klick auf „Generieren" wird der Klartext einmalig hier angezeigt, mit dem nächsten Schritt im Terminal; danach ist nur der Hash in der DB.');
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
                .   '<code id="ai_cli_token_oneshot" style="flex:1;padding:.4em .6em;background:#fff;color:#222;border:1px solid #ccc;border-radius:.2em;font-family:monospace;word-break:break-all;user-select:all;">%s</code>'
                .   '<button type="button" class="tl_submit" style="white-space:nowrap;" '
                .     'onclick="var b=this;navigator.clipboard.writeText(document.getElementById(&#039;ai_cli_token_oneshot&#039;).textContent).then(function(){b.textContent=%s;setTimeout(function(){b.textContent=%s;},2000);});">%s</button>'
                . '</div>'
                . '%s'
                . '</div>',
                $this->h($this->t('tl_user.ai_cli_token_oneshot_warning', 'Klartext-Token (NUR JETZT sichtbar — bitte kopieren):')),
                $this->h($oneShotToken),
                $copiedJs,
                $copyJs,
                $this->h($copyLabel),
                $this->nextStep(),
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

    /**
     * The second half of the bridge, under the token it needs (v0.16.3).
     *
     * Generating the token looked like the whole setup; contao-ai-cli then reported the
     * bridge as not configured and nothing here said why.
     * Said as optional — only `bridge clone`/`bridge rewrite` from the
     * terminal use the token, the chat does not. The command carries this site's address
     * and no token: without `--token`/`--token-stdin` the CLI asks for it with hidden
     * input, so it never stands in a command line or the shell history.
     *
     * Always `https://`: the CLI refuses any other bridge URL, and behind a TLS proxy
     * without trusted_proxies the request's own scheme reads http.
     * Fixed text colours: the box keeps its light background in the dark back-end theme.
     */
    private function nextStep(): string
    {
        $host    = $this->requestStack->getCurrentRequest()?->getHttpHost();
        $command = \sprintf('contao-ai-cli --session <name> bridge configure --url https://%s --test', $host ?: '<site>');

        return \sprintf(
            '<p style="margin:1em 0 .4em;color:#4a3d00;">%s</p>'
            . '<code style="display:block;padding:.4em .6em;background:#fff;color:#222;border:1px solid #ccc;border-radius:.2em;font-family:monospace;word-break:break-all;user-select:all;">%s</code>',
            $this->h($this->t(
                'tl_user.ai_cli_token_next_step',
                'Optional, nur für „bridge clone" und „bridge rewrite" aus dem Terminal — der Chat braucht den Token nicht. Danach im Terminal ausführen; die CLI fragt verdeckt nach dem Token:',
            )),
            $this->h($command),
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
