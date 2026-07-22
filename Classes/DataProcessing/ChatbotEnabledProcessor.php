<?php

declare(strict_types=1);

namespace Ocular\Chatbot\DataProcessing;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Frontend\ContentObject\ContentContentObject;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

class ChatbotEnabledProcessor implements DataProcessorInterface
{

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ) {

        $processedData['chatbotEnabled'] =  (bool)$this->extensionConfiguration->get('chatbot','chatbotEnabled');
        return $processedData;

    }
}
