<?php

declare(strict_types=1);

/*
 * The chat page is a Symfony route (AiChatController) and its menu entry comes
 * from BackendMenuListener. This entry stays for one reason: Contao builds the
 * "Allowed modules" checkboxes of users and user groups from BE_MOD, and
 * AiAccessVoter grants the chat to editors who have `ai_chat` ticked there.
 *
 * - `hideInNavigation` keeps it out of the menu — it would link to the legacy
 *   address `?do=ai_chat`, which LegacyChatUrlListener redirects.
 * - No `disablePermissionChecks`: that would drop the checkbox, and with it the
 *   only way to grant the chat to someone who is not an admin.
 *
 * Until 0.10.0 this was `'callback' => AiChatModule::class` with a
 * `be_ai_chat.html5` wrapper, which Contao 6 cannot render (issue #26).
 */
$GLOBALS['BE_MOD']['system']['ai_chat'] = [
    'hideInNavigation' => true,
];
