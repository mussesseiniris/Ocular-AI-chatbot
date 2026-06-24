<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Crawler;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class AboutUsCrawler
{
    private Client $client;

    // Maps department section headings to the same serviceTypes vocabulary used in projects
    private array $departmentServiceTypeMap = [
        'Management and Strategy' => ['Platforms', 'Communication', 'Experiences'],
        'Online'                  => ['Platforms'],
        'Creative and Video'      => ['Communication', 'Experiences'],
    ];

    // Maps department section headings to the same tags vocabulary used in projects
    private array $departmentTagMap = [
        'Management and Strategy' => ['Brand', 'Web Development', 'Campaign'],
        'Online'                  => ['Web Development', 'Platform Architecture', 'CRM'],
        'Creative and Video'      => ['Video', 'Graphic Design', 'Brand', 'UX Design'],
    ];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://ocular.nz',
            'timeout'  => 10,
        ]);
    }

    /**
     * Scrapes the about us page and returns structured team member data.
     * Each person is grouped under their department section.
     *
     * Page structure:
     * - h3 tags mark department sections e.g. "Management and Strategy"
     * - Each person block: strong tag (name) followed by em tag (role) inside p tags
     *
     * @return array List of team members with name, role, department, serviceTypes, tags
     */
    public function getTeamMembers(): array
    {
        $html    = $this->client->get('/about-us/')->getBody()->getContents();
        $crawler = new Crawler($html);
        $members = [];

        $currentDepartment = '';

        $crawler->filter('div.frame-type-header, div.ce-bodytext')->each(function (Crawler $node) use (&$members, &$currentDepartment) {

        // Department heading block
            if ($node->matches('div.frame-type-header')) {
                $h3 = $node->filter('h3');
                if ($h3->count() > 0) {
                    $text = trim($h3->text());
                    if (isset($this->departmentServiceTypeMap[$text])) {
                        $currentDepartment = $text;
                    }
                }
                return;
            }

        // Person block — strong (name) + i (role) inside a p tag
            if ($node->matches('div.ce-bodytext')) {
                $node->filter('p')->each(function (Crawler $p) use (&$members, &$currentDepartment) {
            
                $name='';
                if ($p->filter('strong')->count() > 0) {
                    $name = trim($p->filter('strong')->first()->text());
                    if (empty($name) || strlen($name) < 2) {
                        return;
                    }
                }

                $role = '';
                $p->filter('i')->each(function (Crawler $iTag) use ($name, &$role) {
                    $iText = trim($iTag->text());
                    if ($iText !== $name && !empty($iText)) {
                        $role = $iText;
                    }
                });

                if (empty($role)) {
                    return;
                }

                $members[] = [
                        'name'         => $name,
                        'role'         => $role,
                        'department'   => $currentDepartment,
                        'serviceTypes' => $this->departmentServiceTypeMap[$currentDepartment] ?? [],
                        'tags'         => $this->departmentTagMap[$currentDepartment] ?? [],
                        'url'          => '/about-us/',
                    ];
                });
            }
        });

        return $members;
    }

    /**
     * Scrapes the agency overview description from the about us page.
     * Targets the opening paragraph block that describes what OCULAR does.
     *
     * @return string The company overview text
     */
    public function getCompanyOverview(): string
    {
        $html    = $this->client->get('/about-us/')->getBody()->getContents();
        $crawler = new Crawler($html);

        $paragraphs = $crawler->filter('p')->each(function (Crawler $node) {
            return trim($node->text());
        });

        // Filter out short fragments (nav links, captions etc) — keep substantive paragraphs only
        $paragraphs = array_filter($paragraphs, fn($p) => strlen($p) > 60);

        return implode("\n\n", array_values($paragraphs));
    }

    /**
     * Builds chunks for ingestion. Produces three chunk types:
     * - agency: one company overview chunk
     * - person: one chunk per team member
     * - department: one chunk per department summarising who works there
     * @return array List of chunks with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $chunks  = [];
        $members = $this->getTeamMembers();

        // Chunk 1: Agency overview — answers "What does Ocular do?" / "Tell me about Ocular"
        $overview = $this->getCompanyOverview();
        if (!empty($overview)) {
            $chunks[] = [
                'content'  => $overview,
                'metadata' => [
                    'name'         => 'OCULAR',
                    'entityType'   => 'agency',
                    'entityId'     => 'agency_ocular',
                    'entityName'   => 'OCULAR',
                    'chunk_type'    => 'company_overview',
                    'serviceTypes' => ['Platforms', 'Communication', 'Experiences'],
                    'tags'         => [],
                    'url'          => '/about-us/',
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        // One chunk per person — answers "Who does UX at Ocular?" / "Who is the director?"
        foreach ($members as $member) {
            $content = "{$member['name']} is {$member['role']} at OCULAR, working in the {$member['department']} team.";

            $chunks[] = [
                'content'  => $content,
                'metadata' => [
                    'name'         => $member['name'],
                    'entityType'   => 'person',
                    'entityId'     => 'person_' . strtolower(str_replace([' ', "'"], ['_', ''], $member['name'])),
                    'entityName'   => $member['name'],
                    'chunk_type'    => 'team_member',
                    // 'role'         => $member['role'],
                    'department'   => $member['department'],
                    'serviceTypes' => $member['serviceTypes'],
                    'tags'         => $member['tags'],
                    'url'          => $member['url'],
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        // One chunk per department — answers "Who works on web projects?" / "Who is in creative?"
        $byDepartment = [];
        foreach ($members as $member) {
            if (!empty($member['department'])) {
                $byDepartment[$member['department']][] = $member;
            }
        }

        foreach ($byDepartment as $department => $deptMembers) {
            $names   = array_map(fn($m) => "{$m['name']} ({$m['role']})", $deptMembers);
            $content = "The {$department} team at OCULAR includes: " . implode(', ', $names) . '.';

            $chunks[] = [
                'content'  => $content,
                'metadata' => [
                    'name'         => $department,
                    'entityType'   => 'department',
                    'entityId'     => 'department_' . strtolower(str_replace(' ', '_', $department)),
                    'entityName'   => $department,
                    'chunk_type'    => 'department_overview',
                    'serviceTypes' => $this->departmentServiceTypeMap[$department] ?? [],
                    'tags'         => $this->departmentTagMap[$department] ?? [],
                    'url'          => '/about-us/',
                    'articleTypes' => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        return $chunks;
    }
}