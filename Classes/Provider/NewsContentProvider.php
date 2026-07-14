<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use Doctrine\DBAL\ParameterType;

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
        $qb->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

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

            // 公共 metadata —— project / article 都一样
            $shared = [
                'name'            => $title,
                'entityType'      => $this->getEntityType(),
                'entityId'        => $this->getEntityType() . '_' . $news['uid'],
                'entityName'      => $title,
                'url'             => $this->getUrlPrefix() . $slug . '/',
                'tags'            => $categories,
                'serviceTypes'    => [],
                'articleTypes'    => [],
                'relatedArticles' => [],
            ];

            // ↓ 会变的那一步：每条记录怎么切 chunk，交给子类
            foreach ($this->buildRecordChunks($news, $shared) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    public function buildChunksForUid(int $uid): array
    {
        $news = $this->fetchRawRecord($uid);
        if ($news === null || (int) $news['pid'] !== $this->getStoragePid()) {
            return [];
        }

        $slug       = trim((string) $news['path_segment'], '/');
        $categories = $this->fetchCategory($uid);

        $shared = [
            'name'            => trim((string) $news['title']),
            'entityType'      => $this->getEntityType(),
            'entityId'        => $this->getEntityType() . '_' . $uid,
            'entityName'      => trim((string) $news['title']),
            'url'             => $this->getUrlPrefix() . $slug . '/',
            'tags'            => $categories,
            'serviceTypes'    => [],
            'articleTypes'    => [],
            'relatedArticles' => [],
        ];

        return $this->buildRecordChunks($news, $shared);
    }

    // public function buildChunks(): array {}

    public function fetchCategory(int $newsUid): array
    {

        $catagoryTable = 'sys_category';
        $middleTable = 'sys_category_record_mm';
        $qb = $this->connectionPool->getQueryBuilderForTable($catagoryTable);
        $qb->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

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

    private function fetchRawRecord(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($this->newsTable);
        $qb->getRestrictions()->removeAll(); // caller checks hidden/deleted itself

        $row = $qb->select('uid', 'pid', 'title', 'teaser', 'bodytext', 'path_segment')
            ->from($this->newsTable)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

}
