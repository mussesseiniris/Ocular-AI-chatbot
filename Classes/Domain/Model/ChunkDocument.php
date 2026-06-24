<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Domain\Model;

use LLPhant\Embeddings\Document;

class ChunkDocument extends Document
{
    public string $chunkId = '';
    public string $entityId = '';
    public string $entityType = '';
    public string $entityName = '';
    public array $serviceTypes = [];
    public array $articleTypes = [];
    public array $tags = [];
    public string $chunkType = '';
    public string $embeddingText = '';
    public array $relatedArticles = [];
    public string $url = '';
}