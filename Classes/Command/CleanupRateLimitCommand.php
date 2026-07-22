<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class CleanupRateLimitCommand extends Command
{
    private ConnectionPool $connectionPool;
    private ExtensionConfiguration $extensionConfiguration;

    public function __construct(ConnectionPool $connectionPool,ExtensionConfiguration $extensionConfiguration)
    {
        parent::__construct();
        $this->connectionPool = $connectionPool;
        $this->extensionConfiguration = $extensionConfiguration;
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes rate limit records older than 24 hours');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionHours = (int) $this->extensionConfiguration->get('chatbot', 'rateLimitRetentionHours');
        $cutoff = time() - ($retentionHours * 3600);

        $connection = $this->connectionPool
            ->getConnectionForTable('tx_chatbot_rate_limit');

        $deleted = $connection->executeStatement(
            'DELETE FROM tx_chatbot_rate_limit WHERE started_at < :cutoff',
            ['cutoff' => $cutoff]
        );

        $output->writeln("Deleted {$deleted} expired rate limit records.");
        return Command::SUCCESS;
    }
}