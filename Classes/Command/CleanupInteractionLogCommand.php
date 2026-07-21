<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class CleanupInteractionLogCommand extends Command
{
    private ConnectionPool $connectionPool;
    private ExtensionConfiguration $extensionConfiguration;

    public function __construct(ConnectionPool $connectionPool, ExtensionConfiguration $extensionConfiguration)
    {
        parent::__construct();
        $this->connectionPool = $connectionPool;
        $this->extensionConfiguration = $extensionConfiguration;
    }

    protected function configure(): void
    {
        $this->setDescription('Deletes interaction log records older than the retention window');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = (int) $this->extensionConfiguration->get('chatbot', 'interactionLogRetentionDays');
        $cutoff = time() - ($retentionDays * 86400);

        $connection = $this->connectionPool
            ->getConnectionForTable('tx_chatbot_interaction_log');

        $deleted = $connection->executeStatement(
            'DELETE FROM tx_chatbot_interaction_log WHERE crdate < :cutoff',
            ['cutoff' => $cutoff]
        );

        $output->writeln("Deleted {$deleted} expired interaction log records.");
        return Command::SUCCESS;
    }
}
