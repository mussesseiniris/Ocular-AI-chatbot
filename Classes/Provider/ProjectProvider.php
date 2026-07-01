<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

class ProjectProvider extends NewsContentProvider
{
    protected function getStoragePid(): int    { return 12; }
    protected function getEntityType(): string { return 'project'; }
    protected function getUrlPrefix(): string  { return '/project/'; }

    protected function buildRecordChunks(array $news, array $shared): array
    {
        $chunks = [];
        $title  = $shared['name'];

        // Chunk 1: overview（teaser）
        $teaser = trim((string) $news['teaser']);
        if ($teaser !== '') {
            $chunks[] = [
                'content'  => $title . ': ' . $teaser,
                'metadata' => array_merge($shared, ['chunk_type' => 'description']),
            ];
        }

        // Chunk 2: details（bodytext）
        $body = $this->htmlToText((string) $news['bodytext']);
        if ($body !== '') {
            $chunks[] = [
                'content'  => $body,
                'metadata' => array_merge($shared, ['chunk_type' => 'detail']),
            ];
        }

        return $chunks;
    }
}