<?php

namespace Ocular\Chatbot\Crawler;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class ProjectsCrawler
{
    private Client $client;
    private array $knownServiceTypes;

    public function __construct()

    {
        $this->client = new Client([
            'base_uri' => 'https://ocular.nz',
            'timeout' => 10
        ]);

        // Top-level service categories used to classify project tags
        $this->knownServiceTypes = ['Platforms', 'Communication', 'Experiences'];
    }

       /**
     * Scrapes the projects listing page and returns basic metadata for each project.
     * Classifies each tag as either a serviceType (top-level category) or a tag (specific skill).
     *
     * @return array List of projects with url, name, tags, and serviceTypes
     */

    public function getProjectList(): array
    {
        $projects = [];
        $html = $this->client->get('/projects/')->getBody()->getContents();
        $knownServiceTypes = $this->knownServiceTypes;
        $crawler = new Crawler($html);
        $crawler->filter('div.project-wrap')->each(function (Crawler $node) use (&$projects, $knownServiceTypes) {
            $url = $node->filter('a')->attr('href');
            $dataGroups = json_decode($node->attr('data-groups'), true);
            $name = $node->filter('a')->attr('title');
            $serviceTypes = [];
            $tags = [];
            
            // Split data-groups into serviceTypes and tags
            foreach ($dataGroups as $group) {
                if (in_array($group, $knownServiceTypes)) {
                    $serviceTypes[] = $group;
                } else {
                    $tags[] = $group;
                }
            }

            $projects[] = [
                'url' => $url,
                'name' => $name,
                'tags' => $tags,
                'serviceTypes' => $serviceTypes,
            ];
        });
        return $projects;
    }

      /**
     * Scrapes a single project detail page and returns its description and main content.
     * Removes the first and last paragraphs to avoid duplicating the description and footer.
     *
     * @param string $url Relative URL of the project page (e.g. /project/light-house-cinema/)
     * @return array Array with 'description' (meta tag) and 'detail' (main body content)
     */
    public function getProjectDetail(string $url): array
    {

        $html = $this->client->get($url)->getBody()->getContents();
        $crawler = new Crawler($html);
        
        // Extract short description from meta tag
        $description = $crawler->filter('meta[name="description"]')->attr('content');

        // Extract all paragraphs from body text sections
        $details = $crawler->filter('div.ce-bodytext p')->each(function (Crawler $node) {
            return trim($node->text());
        });
        
        // Remove empty paragraphs, re-index, and strip first (duplicate) and last (footer) paragraphs
        $details = array_filter($details);
        $details = array_values($details);
        $details = array_slice($details, 1, -1);
        $detail = implode("\n\n", array_filter($details));
        return [
            'description' => $description,
            'detail' => $detail,
        ];
    }

 /**
     * Builds the full list of chunks by combining metadata from getProjectList()
     * and content from getProjectDetail(). Each project produces two chunks:
     * one for the description and one for the detailed content.
     *
     * @return array List of chunks, each with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $contents = [];
        $projects = $this->getProjectList();
        foreach ($projects as $project) {
            echo "Scrapping project : {$project['name']}\n";
            $projectDetails = $this->getProjectDetail($project['url']);

            //chunk1: short project overview
            $contents[] = [
                'content' => $projectDetails['description'],
                'metadata' => [
                    'name' => $project['name'],
                    'chunk_type' => 'description',
                    'url' => $project['url'],
                    'tags' => $project['tags'],
                    'serviceTypes' => $project['serviceTypes'],
                    'entityType'   => 'project',
                    'entityId'     => 'project_' . strtolower(str_replace(' ', '_', $project['name'])),
                    'entityName'   => $project['name'],
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ],
            ];
            
            //chunk2: full project detail
            $contents[] = [
                'content' => $projectDetails['detail'],
                'metadata' => [
                    'name' => $project['name'],
                    'chunk_type' => 'detail',
                    'url' => $project['url'],
                    'tags' => $project['tags'],
                    'serviceTypes' => $project['serviceTypes'],
                    'entityType'   => 'project',
                    'entityId'     => 'project_' . strtolower(str_replace(' ', '_', $project['name'])),
                    'entityName'   => $project['name'],
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ]
            ];
        }
        return $contents;
    }
}
