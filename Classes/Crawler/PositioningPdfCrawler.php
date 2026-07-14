<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Crawler;

use Smalot\PdfParser\Parser;

class PositioningPdfCrawler
{
    /**
     * Pages of interest and their chunk metadata, keyed by page index.
     * Single source of truth: getPdfText() and buildChunks() both read this,
     * so a layout change in the PDF only needs updating here.
     */
    private const PAGES = [
        3 => ['chunkType' => 'company_introduction', 'entityType' => 'agency',   'tags' => ['Company_introduction', 'ocular_introduction']],
        4 => ['chunkType' => 'Brand',                'entityType' => 'agency',   'tags' => ['Brand', 'Positioning']],
        6 => ['chunkType' => 'service',              'entityType' => 'services', 'tags' => ['service', 'services']],
    ];

    private string $pdfPath;

    public function __construct()
    {
        $this->pdfPath = __DIR__ . '/../../Resources/Private/Pdfs/Positioning-and-tone-of-voice.pdf';
    }

    /**
     * Returns the text of each page listed in PAGES, keyed by page index so
     * callers can match texts back to their metadata. Fails loudly when a page
     * is missing or nearly empty — that means the PDF layout has changed and
     * PAGES needs updating, which must not go unnoticed.
     */
    public function getPdfText(): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($this->pdfPath);
        $pages = $pdf->getPages();

        $paragraphs = [];
        foreach (array_keys(self::PAGES) as $pageIndex) {
            if (!isset($pages[$pageIndex])) {
                throw new \RuntimeException(
                    "Positioning PDF has no page index {$pageIndex} — layout changed? Update PAGES in " . self::class
                );
            }
            $text = trim($pages[$pageIndex]->getText());
            if (strlen($text) <= 50) {
                throw new \RuntimeException(
                    "Positioning PDF page {$pageIndex} yielded almost no text — the document layout has probably changed."
                );
            }
            $paragraphs[$pageIndex] = $text;
        }
        return $paragraphs;
    }

    public function buildChunks(): array
    {
        $chunks = [];
        foreach ($this->getPdfText() as $pageIndex => $text) {
            $meta = self::PAGES[$pageIndex];
            $chunks[] = [
                'content' => $text,
                'metadata' => [
                    'chunk_type'      => $meta['chunkType'],
                    'url'             => '',
                    'tags'            => $meta['tags'],
                    'serviceTypes'    => [],
                    'entityType'      => $meta['entityType'],
                    // Page-based ID keeps chunk IDs unique and readable
                    // (chunk_positioning_pdf_page_3_... instead of chunk__...).
                    'entityId'        => 'positioning_pdf_page_' . $pageIndex,
                    'entityName'      => 'Positioning and Tone of Voice (PDF)',
                    'articleTypes'    => [],
                    'relatedArticles' => [],
                ],
            ];
        }
        return $chunks;
    }
}
