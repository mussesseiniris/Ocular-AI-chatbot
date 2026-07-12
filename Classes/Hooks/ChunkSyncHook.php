<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;

class ChunkSyncHook
{
    private const WATCHED_TABLES = ['tx_news_domain_model_news', 'tt_content'];

    public function __construct(
        private readonly \Ocular\Chatbot\Service\ChunkSyncService $syncService
    ) {}

    /**
     * Fires once after ALL datamap operations in a request finish (creates + updates,
     * including hides — hiding sets `hidden=1` via datamap, it is NOT a cmdmap command).
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->datamap as $table => $records) {
            if (!in_array($table, self::WATCHED_TABLES, true)) {
                continue;
            }

            foreach (array_keys($records) as $uid) {
                // New records arrive as "NEWxxxxx" placeholders; resolve to the real uid.
                $realUid = is_numeric($uid)
                    ? (int) $uid
                    : ($dataHandler->substNEWwithIDs[$uid] ?? null);

                if ($realUid !== null) {
                    $this->syncService->resync($table, $realUid);
                }
            }
        }
    }

    /**
     * Fires after each individual cmdmap command (delete, move, copy, undelete...).
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        $id,
        $value,
        DataHandler $dataHandler,
        $pasteUpdate,
        $pasteDatamap
    ): void {
        if (!in_array($table, self::WATCHED_TABLES, true) || $command !== 'delete') {
            return;
        }

        $this->syncService->removeByRecord($table, (int) $id);
    }
}