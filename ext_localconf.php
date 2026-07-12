<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
    '@import "EXT:chatbot/Configuration/TypoScript/setup.typoscript"'
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants(
    '@import "EXT:chatbot/Configuration/TypoScript/constants.typoscript"'
);

(function () {
    // Plugin A: ask a question (default action = ask)
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'Chatbot',
        'Chatbot',
        [ \Ocular\Chatbot\Controller\ChatController::class => 'ask' ],
        [ \Ocular\Chatbot\Controller\ChatController::class => 'ask' ]
    );

    // Plugin B: read transcript (default action = history)
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'Chatbot',
        'History',
        [ \Ocular\Chatbot\Controller\ChatController::class => 'history' ],
        [ \Ocular\Chatbot\Controller\ChatController::class => 'history' ]
    );

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = \Ocular\Chatbot\Hooks\ChunkSyncHook::class;

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
        = \Ocular\Chatbot\Hooks\ChunkSyncHook::class;
})();

