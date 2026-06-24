<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Crawler;

use PhpParser\Node\Scalar\MagicConst\Dir;
use Smalot\PdfParser\Parser;

class PositioningPdfCrawler
{

    private string $pdfPath;
    public function __construct()
    {

        $this->pdfPath = __DIR__ . '/../../Resources/Private/Pdfs/Positioning-and-tone-of-voice.pdf';
    }

    public function getPdfText(): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($this->pdfPath);
        $pages = $pdf->getPages();
        $paragraphs = [];
        foreach ([3, 4, 6] as $pageIndex) {
            $text = $pages[$pageIndex]->getText();
            if (strlen(trim($text)) > 50) {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }

    public function buildChunks(): array
    {
        $contents = [];
        $pageTags=[3=>['Company_introduction','ocular_introduction'],4=>['Brand','Positioning'],6=>['service','services']];
        $pageEntityTypes = [3=>'agency',4=>'agency',6=>'services'];
        $pageChunkTypes = [3=>'company_introduction',4=>'Brand',6=>'service'];
        $paragraphs = $this->getPdfText();
        foreach ([3,4,6] as $index=>$pageIndex) {
            $contents[] = [
                'content' => $paragraphs[$index],
                'metadata' => [
                    'chunk_type' => $pageChunkTypes[$pageIndex],
                    'url' => '',
                    'tags' => $pageTags[$pageIndex],
                    'serviceTypes' => [],
                    'entityType'   => $pageEntityTypes[$pageIndex],
                    'entityId'     => '',
                    'entityName'   => '',
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ],
            ];
        }
        return $contents;
    }
}
