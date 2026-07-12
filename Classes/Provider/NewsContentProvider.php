<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

use TYPO3\CMS\Core\Database\ConnectionPool;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;


abstract class NewsContentProvider
{
    use HtmlToTextTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        protected readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    abstract protected function getStoragePid(): int;
    abstract protected function getEntityType(): string;
    abstract protected function getUrlPrefix(): string;
    abstract protected function buildRecordChunks(array $news, array $shared): array;

    private string $newsTable = 'tx_news_domain_model_news';
    // public function buildChunks(): array {}

    public function fetchNews(): array
    {

        $qb = $this->connectionPool->getQueryBuilderForTable($this->newsTable);
        $qb->getRestrictions()->add(GeneralUtility::makeInstance(FrontendGroupRestriction::class));

        return $qb->select('uid', 'title', 'teaser', 'bodytext', 'path_segment')
            ->from($this->newsTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($this->getStoragePid(), ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    // abstract protected function getStoragePid(): int;

    public function buildChunks(): array
    {
        $chunks = [];

        foreach ($this->fetchNews() as $news) {
            $title = trim((string) $news['title']);
            echo "Processing {$this->getEntityType()}: {$title}\n";

            $slug       = trim((string) $news['path_segment'], '/');
            $categories = $this->fetchCategory((int) $news['uid']);

          
            $shared = [
                'name'            => $title,
                'entityType'      => $this->getEntityType(),
                'entityId'        => $this->getEntityType() . '_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)),
                'entityName'      => $title,
                'url'             => $this->getUrlPrefix() . $slug . '/',
                'tags'            => $categories,
                'serviceTypes'    => [],
                'articleTypes'    => [],
                'relatedArticles' => [],
            ];

            
            foreach ($this->buildRecordChunks($news, $shared) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    // public function buildChunks(): array {}

    public function fetchCategory(int $newsUid): array
    {

        $catagoryTable = 'sys_category';
        $middleTable = 'sys_category_record_mm';
        $qb = $this->connectionPool->getQueryBuilderForTable($catagoryTable);

        return $qb->select('c.title')
            ->from($catagoryTable, 'c')
            ->join('c', $middleTable, 'mm', $qb->expr()->eq('mm.uid_local', 'c.uid'))
            ->where(
                $qb->expr()->eq('mm.uid_foreign', $qb->createNamedParameter($newsUid, ParameterType::INTEGER)),
                $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter($this->newsTable)),
                $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories'))
            )
            ->executeQuery()
            ->fetchFirstColumn();
    }

}
