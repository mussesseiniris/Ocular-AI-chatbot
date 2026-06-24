<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use Exception;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentUtils;
use LLPhant\Embeddings\VectorStores\Qdrant\QdrantVectorStore;
use Ocular\Chatbot\Domain\Model\ChunkDocument;
use Qdrant\Config;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\VectorStruct;

class QdrantIngester extends QdrantVectorStore
{
    private string $collectionName;

    public function __construct(Config $config, string $collectionName, ?string $vectorName = null)
    {
        parent::__construct($config, $collectionName, $vectorName);
        $this->collectionName = $collectionName;
    }

    /**
     * @throws Exception
     */
    public function addDocument(Document $document): void
    {
        if (!$document instanceof ChunkDocument) {
            parent::addDocument($document);
            return;
        }

        if (!is_array($document->embedding)) {
            throw new Exception('Document must have an embedding before ingesting.');
        }

        // $id = DocumentUtils::formatUUIDFromUniqueId(DocumentUtils::getUniqueId($document));

        $id = DocumentUtils::formatUUIDFromUniqueId($document->chunkId);

        $points = new PointsStruct();
        $points->addPoint(
            new PointStruct(
                $id,
                new VectorStruct($document->embedding, self::QDRANT_OPENAI_VECTOR_NAME),
                [
                    'chunk_id'       => $document->chunkId,
                    'entity_id'      => $document->entityId,
                    'entity_type'    => $document->entityType,
                    'entity_name'    => $document->entityName,
                    'service_types'  => $document->serviceTypes,
                    'tags'           => $document->tags,
                    'chunk_type'     => $document->chunkType,
                    'content'        => $document->content,
                    'embedding_text' => $document->embeddingText,
                    'source_name'    => $document->sourceName,
                    'article_type'   => $document->articleTypes,
                    'related_articles' => $document->relatedArticles,
                    'url'              => $document->url,
                ]
            )
        );

        $this->client->collections($this->collectionName)->points()->upsert($points);
    }
}