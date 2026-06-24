<?php
/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Ocular Chatbot',
    'description' => 'AI RAG chatbot extension for Ocular website',
    'category' => 'plugin',
    'author' => 'Ocular',
    'state' => 'alpha',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.99.99',
        ],
    ],
];