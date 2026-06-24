<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

class RateLimitService
{
    // Maximum questions allowed per 24 hour window
    private const LIMIT = 200;
    
    // 24 hours in seconds
    private const WINDOW = 86400;

    private string $secret;

    private ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
        $this->secret = getenv('RATE_LIMIT_SECRET') ?: 'fallback-secret-change-me';
    }

    /**
     * Returns true if the request is allowed, false if limit exceeded
     */
    public function isAllowed(string $ip): bool
    {
        $ipHash = hash_hmac('sha256', $ip, $this->secret);
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable('tx_chatbot_rate_limit');

        $record = $connection->select(
            ['question_count', 'started_at'],
            'tx_chatbot_rate_limit',
            ['ip_hash' => $ipHash]
        )->fetchAssociative();

        // No record — new IP, fall straight through to upsert
        if (!$record) {
            $connection->executeStatement(
                'INSERT INTO tx_chatbot_rate_limit (ip_hash, question_count, started_at)
                VALUES (:ip_hash, 1, :now)',
                ['ip_hash' => $ipHash, 'now' => $now]
            );
            return true;
        }

        $windowExpired = ($now - $record['started_at']) >= self::WINDOW;

        // Within window and over limit — block without touching DB
        if (!$windowExpired && $record['question_count'] >= self::LIMIT) {
            return false;
        }

        // Atomic update — handles window reset and incrementing
        $connection->executeStatement(
            'UPDATE tx_chatbot_rate_limit SET
                question_count = IF((:now - started_at) >= :window, 1, question_count + 1),
                started_at     = IF((:now - started_at) >= :window, :now, started_at)
            WHERE ip_hash = :ip_hash',
            [
                'ip_hash' => $ipHash,
                'now'     => $now,
                'window'  => self::WINDOW,
            ]
        );

        return true;
    }
}