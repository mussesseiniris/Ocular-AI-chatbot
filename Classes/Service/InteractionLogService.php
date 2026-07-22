<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use Psr\Log\LoggerInterface;

class InteractionLogService
{
    private ConnectionPool $connectionPool;
    private LoggerInterface $logger;

    public function __construct(ConnectionPool $connectionPool, LoggerInterface $logger)
    {
        $this->connectionPool = $connectionPool;
        $this->logger = $logger;
    }

    public function log(string $sessionId, int $turn, int $chunksFound, string $ipHash,string $topTopic, string $status): void
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('tx_chatbot_interaction_log');
            $connection->insert('tx_chatbot_interaction_log', [
                'crdate' => time(),
                'session_id' => $sessionId,
                'turn' => $turn,
                'chunks_found' => $chunksFound,
                'ip_hash'=>$ipHash,
                'top_topic' => $topTopic,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[InteractionLogService] Failed to write interaction log: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
