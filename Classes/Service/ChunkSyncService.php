<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use Ocular\Chatbot\Domain\Model\ChunkDocument;
use Ocular\Chatbot\Embeddings\Voyage4EmbeddingGenerator;
use Ocular\Chatbot\Provider\AboutUsProvider;
use Ocular\Chatbot\Provider\ArticleProvider;
use Ocular\Chatbot\Provider\ProjectProvider;
use Ocular\Chatbot\Provider\ServiceProvider;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ChunkSyncService
{
    private const PID_PROJECTS = 12;
    private const PID_ARTICLES = 19;
    private const PID_ABOUT_US = 2;
    private const PID_SERVICES = 6;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ProjectProvider $projectProvider,
        private readonly ArticleProvider $articleProvider,
        private readonly AboutUsProvider $aboutUsProvider,
        private readonly ServiceProvider $serviceProvider,
        private readonly QdrantIngester $qdrantIngester,
        private readonly Voyage4EmbeddingGenerator $embeddingGenerator,
        private readonly LoggerInterface $logger
    ) {}

    // Entry points called by ChunkSyncHook
    public function resync(string $table, int $uid): void
    {
        try {
            match ($table) {
                'tx_news_domain_model_news' => $this->resyncNews($uid),
                'tt_content'                => $this->resyncContent($uid),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error('[ChunkSyncService] resync failed', [
                'table' => $table, 'uid' => $uid, 'exception' => $e,
            ]);
        }
    }

    public function removeByRecord(string $table, int $uid): void
    {
        try {
            match ($table) {
                'tx_news_domain_model_news' => $this->removeNews($uid),
                'tt_content'                => $this->removeContent($uid),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error('[ChunkSyncService] removeByRecord failed', [
                'table' => $table, 'uid' => $uid, 'exception' => $e,
            ]);
        }
    }


    // News (projects / articles) — targeted single-record resync
    private function resyncNews(int $uid): void
    {
        $raw = $this->fetchRawNewsRow($uid);
        if ($raw === null) {
            $this->logger->warning('[ChunkSyncService] news row vanished (hard delete?)', ['uid' => $uid]);
            $this->removeNewsBothPrefixes($uid);
            return;
        }

        $provider = $this->newsProviderForPid((int) $raw['pid']);
        if ($provider === null) {
            return; // Not a watched storage page — ignore.
        }

        $prefix   = $provider === $this->projectProvider ? 'project' : 'article';
        $entityId = $prefix . '_' . $uid;

        $visible = ((int) $raw['hidden'] === 0 && (int) $raw['deleted'] === 0);

        // Always clear the old chunks for this entity first — a record may
        // previously have produced 2 chunks (description + detail) and now
        // produce fewer (e.g. teaser cleared), so start clean.
        $this->qdrantIngester->deleteByEntityId($entityId);

        if (!$visible) {
            return; // Hidden/soft-deleted: leave it removed.
        }

        $chunks = $provider->buildChunksForUid($uid);
        $this->embedAndUpsert($chunks);
    }

    private function removeNews(int $uid): void
    {
        $raw = $this->fetchRawNewsRow($uid); // soft-delete: row usually still readable
        $prefix = $raw !== null ? $this->prefixForPid((int) $raw['pid']) : null;

        if ($prefix === null) {
            // Truly gone (hard delete) and we never learned its pid — clear both
            // possible prefixes rather than leaving an unresolvable orphan.
            $this->removeNewsBothPrefixes($uid);
            return;
        }

        $this->qdrantIngester->deleteByEntityId($prefix . '_' . $uid);
    }

    private function removeNewsBothPrefixes(int $uid): void
    {
        $this->qdrantIngester->deleteByEntityId('project_' . $uid);
        $this->qdrantIngester->deleteByEntityId('article_' . $uid);
    }

    private function newsProviderForPid(int $pid): ProjectProvider|ArticleProvider|null
    {
        return match ($pid) {
            self::PID_PROJECTS => $this->projectProvider,
            self::PID_ARTICLES => $this->articleProvider,
            default => null,
        };
    }

    private function prefixForPid(int $pid): ?string
    {
        return match ($pid) {
            self::PID_PROJECTS => 'project',
            self::PID_ARTICLES => 'article',
            default => null,
        };
    }

    private function fetchRawNewsRow(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $qb->getRestrictions()->removeAll(); // see resync() — deliberately unrestricted

        $row = $qb->select('uid', 'pid', 'hidden', 'deleted')
            ->from('tx_news_domain_model_news')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }


    // tt_content (about-us / services) — full-section resync
    //
    // Deliberately NOT record-scoped: a single team-member row can affect
    // the shared "department" aggregate chunk, and a single service's name
    // vs. description live in separate sibling rows. Rebuilding the whole
    // small section is simpler and safer than diffing individual rows —
    // these sources produce a handful of chunks each, so the cost is trivial.

    private function resyncContent(int $uid): void
    {
        $pid = $this->fetchRawContentPid($uid);
        if ($pid === null) {
            // Row vanished entirely — we don't know which section it was in,
            // so resync both watched sections to be safe. Cheap, since both
            // are small, and guarantees no stale aggregate survives.
            $this->rebuildAboutUs();
            $this->rebuildServices();
            return;
        }

        match ($pid) {
            self::PID_ABOUT_US => $this->rebuildAboutUs(),
            self::PID_SERVICES => $this->rebuildServices(),
            default => null,
        };
    }

    private function removeContent(int $uid): void
    {
        // Same reasoning as resync: we can't cheaply tell which section an
        // already-gone tt_content row belonged to, so just rebuild both.
        $this->rebuildAboutUs();
        $this->rebuildServices();
    }

    private function rebuildAboutUs(): void
    {
        foreach (['agency', 'person', 'department'] as $entityType) {
            $this->qdrantIngester->deleteByEntityType($entityType);
        }
        $this->embedAndUpsert($this->aboutUsProvider->buildChunks());
    }

    private function rebuildServices(): void
    {
        $this->qdrantIngester->deleteByEntityType('service');
        $this->embedAndUpsert($this->serviceProvider->buildChunks());
    }

    private function fetchRawContentPid(int $uid): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();

        $pid = $qb->select('pid')
            ->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $pid === false ? null : (int) $pid;
    }


    // Shared embed + upsert helper (mirrors IngestCommand's loop)
    private function embedAndUpsert(array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $metadata = $chunk['metadata'];

            if (trim((string) $chunk['content']) === '') {
                continue;
            }

            $doc = new ChunkDocument();
            $doc->content = $chunk['content'];
            $doc->embeddingText = $chunk['content'];
            $doc->chunkId = 'chunk_' . strtolower(str_replace(' ', '_', $metadata['entityId'])) . '_' . $metadata['chunk_type'];
            $doc->entityId = $metadata['entityId'];
            $doc->entityType = $metadata['entityType'];
            $doc->entityName = $metadata['entityName'];
            $doc->serviceTypes = $metadata['serviceTypes'];
            $doc->tags = $metadata['tags'];
            $doc->chunkType = $metadata['chunk_type'];
            $doc->sourceName = $metadata['url'];
            $doc->articleTypes = $metadata['articleTypes'];
            $doc->relatedArticles = $metadata['relatedArticles'];
            $doc->url = $metadata['url'];

            $this->embeddingGenerator->embedDocument($doc);

            if (empty($doc->embedding)) {
                $this->logger->error('[ChunkSyncService] Empty embedding', ['chunk_id' => $doc->chunkId]);
                continue;
            }

            $this->qdrantIngester->addDocument($doc);
        }
    }
}