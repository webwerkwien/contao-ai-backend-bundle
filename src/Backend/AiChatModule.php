<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiBackendBundle\Backend;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\System;
use Webwerkwien\ContaoAiBackendBundle\Service\ChatViewRenderer;

/**
 * Thin wrapper around ChatViewRenderer required by Contao's legacy BE_MOD['callback'] mechanism.
 * Render logic and dependencies live in ChatViewRenderer (testable via constructor injection);
 * this class only bridges the legacy callback contract.
 *
 * `$Template` is resolved by `Contao\Controller::__get()` at runtime and has no
 * declaration to find, so it is declared here instead of silenced globally — a
 * genuinely misspelled property should still be an error.
 *
 * @property \Contao\BackendTemplate $Template
 */
class AiChatModule extends BackendModule
{
    protected $strTemplate = 'be_ai_chat';

    protected function compile(): void
    {
        $user = BackendUser::getInstance();
        if (!$user instanceof BackendUser) {
            $this->Template->content = '<p class="tl_error">No backend session.</p>';
            return;
        }

        /** @var ChatViewRenderer $renderer */
        $renderer = System::getContainer()->get(ChatViewRenderer::class);
        $this->Template->content = $renderer->render($user);
    }
}
