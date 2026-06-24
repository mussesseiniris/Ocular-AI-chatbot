<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Crawler;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class ArticlesCrawler
{
    private Client $client;
    private array $knownArticleType;

    /**
     * Keyword-to-tag map used to auto-detect tags from article title and description.
     */
    private array $articleContentTagMap = [
    // Aligned with project tag vocabulary
    'brand'     => 'Brand',              
    'branding'  => 'Brand',
    'ux'        => 'UX Design',          
    'video'     => 'Video',              
    'web'       => 'Web Development',    
    'website'   => 'Web Development',
    'campaign'  => 'Campaign',           
    'graphic'   => 'Graphic Design',
    'online projects'    => 'Web Development',

    // Article-specific tags with no project equivalent
    'strategy'  => 'Strategy',
    'process'   => 'Process',
    'design'    => 'Design Thinking',
    'digital'   => 'Digital',
    'platform'  => 'Platform Architecture',
    'online projects'    => 'Process',

    // Content type signals
    'how to'    => 'Guide',
    'why'       => 'Opinion',
    'what is'   => 'Explainer',
    // 'our'       => 'Agency Insight',
    // 'we '       => 'Agency Insight',
    ];


    /**
     * Maps process article URLs to stable entity IDs.
     * These IDs match what the ArticleCrawler generates for the same articles,
     * so that relatedArticles references in service chunks resolve correctly.
     */
    private array $processArticleUrlMap = [
    '/article/the-design-process-at-ocular/'        => 'article_process_design',
    '/article/how-we-bring-online-projects-to-life/' => 'article_process_online',
    '/article/the-video-process-at-ocular/'          => 'article_process_video',
    ];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://ocular.nz',
            'timeout'  => 10,
        ]);

        // Top-level service categories used to classify project tags
        $this->knownArticleType = ['Industry Insights', 'Updates', 'Live Work Bay'];
    }

    /**
     * Scrapes all article listing pages (pagination handled) and returns
     * basic metadata per article.
     *
     * Page structure observed:
     * - Articles listing at /articles/ with pagination at /articles/page2/ etc.
     * - Each article card: h4 > a (title + url), category span text, summary paragraph
     * - Article detail pages at /article/slug/
     *
     * @return array List of articles with url, title, category, summary
     */


    public function getArticleList(): array
    {
        $articles   = [];
        $listingUrl = '/articles/';

        // Walk all pagination pages until no "next" link found
        while (!empty($listingUrl)) {
            $html      = $this->client->get($listingUrl)->getBody()->getContents();
            $crawler   = new Crawler($html);

            // Each article card — h4 contains the link, category is nearby text
            $crawler->filter('h4')->each(function (Crawler $node) use (&$articles) {
                $linkNode = $node->filter('a');
                if ($linkNode->count() === 0) {
                    return;
                }

                $url   = $linkNode->attr('href');
                $title = trim($linkNode->text());

                if (empty($url) || empty($title)) {
                    return;
                }

                // Make URL relative if absolute
                $url = str_replace('https://ocular.nz', '', $url);

                // Category sits as text in a sibling or parent element near the h4
                // On the listing page the category appears as plain text after the h4 link
                $articleTypes = [];
                $tags=[];
                try {
                    // Try to get the next sibling text node containing the category
                    $parentText = trim($node->closest('article, .news-item, li, div')->text());
                    // Category names we know: Industry Insights, Updates, Live Work Bay
                    foreach ($this->knownArticleType as $cat) {
                        if (str_contains($parentText, $cat)) {
                            $articleTypes[] = $cat;
                        } else {
                            $tags[] = $cat;
                        }
                    }
                } catch (\Exception $e) {
                    // If DOM traversal fails, leave category empty — tags will be auto-detected
                }

                $articles[] = [
                    'url'      => $url,
                    'title'    => $title,
                    'tags'     => $tags,
                    'articleType' => $articleTypes,
                ];
            });

            // Check for pagination — look for "next" link
            $nextLink = $crawler->filter('a')->reduce(function (Crawler $node) {
                return trim(strtolower($node->text())) === 'next';
            });

            $listingUrl = $nextLink->count() > 0
                ? str_replace('https://ocular.nz', '', $nextLink->attr('href'))
                : null;
        }

        return $articles;
    }

    /**
     * Scrapes a single article detail page and returns its content.
     *
     * Page structure observed:
     * - h1 = article title
     * - Category label as plain text after back link
     * - Body content in paragraphs, h2 headings, lists
     * - No ce-bodytext wrapper — content sits in the main content area directly
     *
     * @param string $url Relative URL e.g. /article/slug/
     * @return array With 'description' (meta), 'body' (full content), 'title' (h1)
     */
    public function getArticleDetail(string $url): array
    {
        $html    = $this->client->get($url)->getBody()->getContents();
        $crawler = new Crawler($html);

        // Meta description for the summary chunk
        $description = '';
        try {
            $description = $crawler->filter('meta[name="description"]')->attr('content');
        } catch (\Exception $e) {
            // No meta description — will fall back to first paragraph
        }


        // Body content — collect all paragraphs and h2 headings
        // Skip nav elements, back links, footer content
        $bodyParts = [];
        $crawler->filter('h2, h3, p')->each(function (Crawler $node) use (&$bodyParts) {
            $text = trim($node->text());

            // Skip navigation, back links, contact section
            if (
                str_starts_with($text, '>') ||
                str_starts_with($text, 'Back') ||
                str_starts_with($text, 'Get in touch') ||
                str_starts_with($text, 'Enquiries') ||
                str_starts_with($text, 'Support') ||
                strlen($text) < 20
            ) {
                return;
            }

            $bodyParts[] = $text;
        });

        // Remove first paragraph if it duplicates the meta description
        if (!empty($bodyParts) && !empty($description) && str_contains($bodyParts[0], substr($description, 0, 50))) {
            array_shift($bodyParts);
        }

        return [
            'description' => $description,
            'body'        => implode("\n\n", $bodyParts),
        ];
    }

    /**
     * Auto-detects tags from article title and description by keyword matching.
     * Uses the same tag vocabulary as projects so filter detection works.
     *
     * @param string $title
     * @param string $description
     * @return array Detected tags
     */
    private function detectTags(string $title, string $description): array
    {
        $text     = strtolower($title . ' ' . $description);
        $detected = [];

        foreach ($this->articleContentTagMap as $keyword => $tag) {
            if (str_contains($text, $keyword)) {
                $detected[] = $tag;
            }
        }

        return array_unique($detected);
    }

    /**
     * Derives a stable article ID from its URL slug.
     * Process articles that appear on the services page use a hardcoded map
     * to ensure their IDs match what ServicesCrawler stored in relatedArticles.
     * All other articles derive their ID from the URL slug.
     *
     * @param string $url
     * @return string
     */
    private function deriveArticleId(string $url): string
    {
        // Check if this is a process article with a known stable ID
        if (isset($this->processArticleUrlMap[$url])) {
            return $this->processArticleUrlMap[$url];
        } else {

        // For /article/slug/ URLs, derive from slug
        $slug = trim(parse_url($url, PHP_URL_PATH), '/');
        $slug = str_replace('article/', '', $slug);
        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug));

        return $slug;
        }
    }

    /**
     * 
     * Builds the full list of chunks by combining metadata from getArticleList()
     * and content from getArticleDetail(). Each article produces two chunks:
     * one for the description and one for the detailed content.
     *
     * @return array List of chunks with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $chunks   = [];
        $articles = $this->getArticleList();

        foreach ($articles as $index => $article) {
            echo "Scrapping article " . ($index + 1) . " of " . count($articles) . ": {$article['title']}\n";

            $detail    = $this->getArticleDetail($article['url']);
            $title     = !empty($detail['title']) ? $detail['title'] : $article['title'];
            $articleId = $this->deriveArticleId($article['url']);
            $tags      = $this->detectTags($title, $detail['description']);
            $articleType = $article['articleType'];

            // Shared metadata used on both chunk types
            $sharedMetadata = [
                'name'         => $title,
                'entityType'   => 'article',
                'entityId'     => $articleId,
                'entityName'   => $title,
                'articleTypes' => $articleType,
                'serviceTypes' => [],
                'tags'         => $tags,
                'url'          => $article['url'],
                'relatedArticles' => [],
            ];

            // Chunk 1: Summary — answers "What articles does Ocular have about branding?"
            if (!empty($detail['description'])) {
                $chunks[] = [
                    'content'  => "{$title}: {$detail['description']}",
                    'metadata' => array_merge($sharedMetadata, ['chunk_type' => 'article_summary']),
                ];
            }

            // Chunk 2: Full body — answers deep content questions about the article
            if (!empty($detail['body'])) {
                $chunks[] = [
                    'content'  => $detail['body'],
                    'metadata' => array_merge($sharedMetadata, ['chunk_type' => 'article_body']),
                ];
            }

            // Small delay to avoid rate limiting 
            usleep(500000);
        }

        return $chunks;
    }
}