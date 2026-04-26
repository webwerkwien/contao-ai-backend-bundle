<?php

declare(strict_types=1);

use Webwerkwien\ContaoAiBackendBundle\Backend\AiChatModule;

$GLOBALS['BE_MOD']['system']['ai_chat'] = [
    'callback' => AiChatModule::class,
    'icon'     => 'bundles/contaoaibackend/img/icon.svg',
];
