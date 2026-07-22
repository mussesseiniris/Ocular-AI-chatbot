<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

class ArticleProvider extends NewsContentProvider
{

    protected function getStoragePid(): int    
    { return (int) $this->extensionConfiguration->get('chatbot', 'articlePid'); }

    protected function getEntityType(): string { return 'article'; }
    protected function getUrlPrefix(): string  { return '/article/'; }

    protected function buildRecordChunks(array $news, array $shared): array
    {
        $title  = $shared['name'];
        $teaser = trim((string) $news['teaser']);
        $body = $this->htmlToText((string) $news['bodytext']);


        $parts   = array_filter([$title, $teaser, $body], fn($p) => $p !== '');
        $content = implode("\n\n", $parts);


        if ($content === '') {
            return [];
        }

        return [
            [
                'content'  => $content,
                'metadata' => array_merge($shared, ['chunk_type' => 'article']),
            ],
        ];
    }
}
