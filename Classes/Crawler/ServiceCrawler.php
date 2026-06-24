<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Crawler;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class ServiceCrawler
{
    private Client $client;

    /*
    * Maps the 3 services to the 3 categories used in projects list page
    */
    private array $serviceToServiceTypeMap = [
    'Digital Platforms'       => 'Platforms',
    'Content & Communication' => 'Communication',
    'UX & Experience Design'  => 'Experiences',
    'Systems & Integration'   => 'Platforms',
    'Emerging Technology'     => '', 
    ];

    /*
    * Services mapped to related tags. Tags the same used for 
    * projects page
    */

    private array $serviceToTagsMap = [
    'Digital Platforms' => [
        'Web Development',
        'Platform Architecture',
        'CRM',
    ],
    'Content & Communication' => [
        'Video',
        'Campaign',
        'Graphic Design',
        'Brand',
    ],
    'UX & Experience Design' => [
        'UX Design',
        'Web Design',
    ],
    'Systems & Integration' => [
        'CRM',
        'Platform Architecture',
    ],
    'Emerging Technology' => [], // no matching tags in knownTags yet
    ];

  /**
     * Maps Ocular service categories to their corresponding process articles.
     * These are the "Our process for X" articles linked from the services page.
     * Stored here so the service category's process article URL and stable ID
     * can be baked into service chunk metadata at ingest time, allowing the LLM
     * to reference the correct article when answering process-related questions.
     *
     * Note: 'Emerging Technology' is omitted as no process article exists for it.
     */
    private array $serviceProcessArticleMap = [
    'UX & Experience Design' => [
        'url'       => '/article/the-design-process-at-ocular/',
        'articleId' => 'article_process_design',
    ],
    'Digital Platforms' => [
        'url'       => '/article/how-we-bring-online-projects-to-life/',
        'articleId' => 'article_process_online',
    ],
    'Systems & Integration' => [
        'url'       => '/article/how-we-bring-online-projects-to-life/',
        'articleId' => 'article_process_online',
    ],
    'Content & Communication' => [
        'url'       => '/article/the-video-process-at-ocular/',
        'articleId' => 'article_process_video',
    ],
    // Emerging Technology omitted — no process article exists
    ];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://ocular.nz',
            'timeout'  => 10,
        ]);
    }

    /**
     * Scrapes the services page and returns structured data per service.
     *
     * @return array List of services with name, description, serviceType, tags, processArticle
     */
    public function getServices(): array
    {
        $html    = $this->client->get('/services/')->getBody()->getContents();
        $crawler = new Crawler($html);
        $services = [];

        $currentService     = '';
        $currentParagraphs  = [];
        $currentTagline     = '';

        $crawler->filter('h2, h3, p')->each(function (Crawler $node) use (&$services, &$currentService, &$currentParagraphs, &$currentTagline) {
            $tag  = $node->nodeName();
            $text = trim($node->text());

            // h2 = new service section — save previous service before starting new one
            if ($tag === 'h2' && isset($this->serviceToServiceTypeMap[$text])) {
                if (!empty($currentService) && !empty($currentParagraphs)) {
                    $services[$currentService] = $this->buildServiceEntry(
                        $currentService,
                        $currentTagline,
                        $currentParagraphs
                    );
                }

                $currentService    = $text;
                $currentParagraphs = [];
                $currentTagline    = '';
                return;
            }

            // h3 immediately after h2 = service tagline
            if ($tag === 'h3' && !empty($currentService) && empty($currentTagline)) {
                $currentTagline = $text;
                return;
            }

            // p tags = service description paragraphs
            // Skip very short strings (link text like "Design case studies")
            if ($tag === 'p' && !empty($currentService) && strlen($text) > 40) {
                $currentParagraphs[] = $text;
            }
        });

        // Save the last service after loop ends
        if (!empty($currentService) && !empty($currentParagraphs)) {
            $services[$currentService] = $this->buildServiceEntry(
                $currentService,
                $currentTagline,
                $currentParagraphs
            );
        }

        return array_values($services);
    }

    /**
     * Assembles a single service entry array from its scraped parts.
     */
    private function buildServiceEntry(string $name, string $tagline, array $paragraphs): array
    {
        return [
            'name'           => $name,
            'tagline'        => $tagline,
            'description'    => implode("\n\n", $paragraphs),
            'serviceType'    => $this->serviceToServiceTypeMap[$name],
            'tags'           => $this->serviceToTagsMap[$name] ?? [],
            'processArticle' => $this->serviceProcessArticleMap[$name] ?? null,
            'url'            => '/services/',
        ];
    }

    /**
     * Builds chunks for ingestion. Produces one chunk per service:
     *
     * - service_overview: tagline + description — answers "What does Ocular offer for X?"
     *   Includes a relatedArticles reference to the process article for that service
     *   category, so the LLM can point users to further reading on how Ocular works.
     * 
     * @return array List of chunks with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $chunks   = [];
        $services = $this->getServices();

        foreach ($services as $service) {
            echo "Scrapping : {$service['name']}\n";
            $content = "{$service['name']}: {$service['tagline']}\n\n{$service['description']}";
            $chunks[] = [
                'content'  => $content,
                'metadata' => [
                    'name'            => $service['name'],
                    'entityType'      => 'service',
                    'entityId'        => 'service_' . strtolower($service['name']),
                    'entityName'      => $service['name'],
                    'chunkType'       => 'service_overview',
                    'serviceTypes'    => [$service['serviceType']],
                    'tags'            => $service['tags'],
                    // explicit article ID baked in — ChatService fetchByIds() uses this
                    'relatedArticles' => $service['processArticle']
                        ? [$service['processArticle']['articleId']]
                        : [],
                    'url'             => $service['url'],
                    'chunk_type'      => 'services',
                    'articleTypes'    => [],
                ],
            ];
        }

        return $chunks;
    }
}