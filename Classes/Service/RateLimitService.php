<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use Psr\Log\LoggerInterface;

class RateLimitService
{
    // Maximum questions allowed per 24 hour window
    private const LIMIT = 100;
    
    // 24 hours in seconds
    private const WINDOW = 86400;

    private string $secret;

    private ConnectionPool $connectionPool;
    private LoggerInterface $logger;

    public function __construct(ConnectionPool $connectionPool, LoggerInterface $logger)
    {
        $this->connectionPool = $connectionPool;
        $this->logger = $logger;

        $configuredSecret = getenv('RATE_LIMIT_SECRET');
        if (empty($configuredSecret)) {
            $this->logger->error('[RateLimitService] RATE_LIMIT_SECRET is not set — falling back to a weak, publicly-known default. IP hashing is not effective until this is configured.');
            $this->secret = 'fallback-secret-change-me';
        } else {
            $this->secret = $configuredSecret;
        }
    }

    /**
     * Returns true if the request is allowed, false if limit exceeded
     */
    public function isAllowed(string $ip): bool
    {
        $ipHash = $this->hashIp($ip);
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable('tx_chatbot_rate_limit');

        $existing = $connection->select(
            ['question_count', 'started_at'],
            'tx_chatbot_rate_limit',
            ['ip_hash' => $ipHash]
        )->fetchAssociative();

        if ($existing) {
            $windowExpired = ($now - $existing['started_at']) >= self::WINDOW;
            if (!$windowExpired && $existing['question_count'] >= self::LIMIT) {
                return false;
            }
        }

        $connection->executeStatement(
            'INSERT INTO tx_chatbot_rate_limit (ip_hash, question_count, started_at)
            VALUES (:ip_hash, 1, :now)
            ON DUPLICATE KEY UPDATE
                question_count = IF((:now - started_at) >= :window, 1, question_count + 1),
                started_at     = IF((:now - started_at) >= :window, :now, started_at)',
            [
                'ip_hash' => $ipHash,
                'now'     => $now,
                'window'  => self::WINDOW,
            ]
        );

        $record = $connection->select(
            ['question_count'],
            'tx_chatbot_rate_limit',
            ['ip_hash' => $ipHash]
        )->fetchAssociative();

        return ($record['question_count'] ?? 0) <= self::LIMIT;
    }

    public function hashIp(string $ip): string
{
    return hash_hmac('sha256', $ip, $this->secret);
}
    
}