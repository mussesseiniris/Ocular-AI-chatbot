<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Builds chatbot chunks for the About Us page straight from the database.
 *
 * Replaces the HTTP-scraping AboutUsCrawler. On the About Us page (pid 2):
 * - Department sections are "header" content elements ("Management and Strategy",
 *   "Online", "Creative and Video"), all siblings inside one container.
 * - Each department's live team members are "textpic" elements inside the
 *   flex-container that follows the department header. (Older 4col-containers
 *   holding duplicate members are hidden or empty and are ignored.)
 * - The company overview lives in plain "text" elements that carry body text.
 *
 * Produces three chunk types, mirroring the previous crawler:
 * - agency: one company overview chunk
 * - person: one chunk per team member
 * - department: one chunk per department summarising who works there
 */
class AboutUsProvider
{
    use HtmlToTextTrait;

    private const STORAGE_PID = 2;

    private string $contentTable = 'tt_content';

    /**
     * Maps department headings to the serviceTypes vocabulary used in projects.
     * The keys also act as the authoritative list of department headers.
     */
    private array $departmentServiceTypeMap = [
        'Management and Strategy' => ['Platforms', 'Communication', 'Experiences'],
        'Online'                  => ['Platforms'],
        'Creative and Video'      => ['Communication', 'Experiences'],
    ];

    /**
     * Maps department headings to the tags vocabulary used in projects.
     */
    private array $departmentTagMap = [
        'Management and Strategy' => ['Brand', 'Web Development', 'Campaign'],
        'Online'                  => ['Web Development', 'Platform Architecture', 'CRM'],
        'Creative and Video'      => ['Video', 'Graphic Design', 'Brand', 'UX Design'],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @return array List of chunks with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $chunks = [];

        // Chunk 1: Agency overview — answers "What does Ocular do?" / "Tell me about Ocular"
        $overview = $this->fetchCompanyOverview();
        if ($overview !== '') {
            $chunks[] = [
                'content'  => $overview,
                'metadata' => [
                    'name'            => 'OCULAR',
                    'entityType'      => 'agency',
                    'entityId'        => 'agency_ocular',
                    'entityName'      => 'OCULAR',
                    'chunk_type'      => 'company_overview',
                    'serviceTypes'    => ['Platforms', 'Communication', 'Experiences'],
                    'tags'            => [],
                    'url'             => '/about-us/',
                    'articleTypes'    => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        $members = $this->fetchTeamMembers();

        // One chunk per person — answers "Who does UX at Ocular?" / "Who is the director?"
        foreach ($members as $member) {
            echo "Processing team member: {$member['name']}\n";

            $content = "{$member['name']} is {$member['role']} at OCULAR, working in the {$member['department']} team.";

            $chunks[] = [
                'content'  => $content,
                'metadata' => [
                    'name'            => $member['name'],
                    'entityType'      => 'person',
                    'entityId'        => 'person_' . strtolower(str_replace([' ', "'"], ['_', ''], $member['name'])),
                    'entityName'      => $member['name'],
                    'chunk_type'      => 'team_member',
                    'department'      => $member['department'],
                    'serviceTypes'    => $this->departmentServiceTypeMap[$member['department']] ?? [],
                    'tags'            => $this->departmentTagMap[$member['department']] ?? [],
                    'url'             => '/about-us/',
                    'articleTypes'    => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        // One chunk per department — answers "Who works on web projects?" / "Who is in creative?"
        $byDepartment = [];
        foreach ($members as $member) {
            if ($member['department'] !== '') {
                $byDepartment[$member['department']][] = $member;
            }
        }

        foreach ($byDepartment as $department => $deptMembers) {
            $names   = array_map(fn($m) => "{$m['name']} ({$m['role']})", $deptMembers);
            $content = "The {$department} team at OCULAR includes: " . implode(', ', $names) . '.';

            $chunks[] = [
                'content'  => $content,
                'metadata' => [
                    'name'            => $department,
                    'entityType'      => 'department',
                    'entityId'        => 'department_' . strtolower(str_replace(' ', '_', $department)),
                    'entityName'      => $department,
                    'chunk_type'      => 'department_overview',
                    'serviceTypes'    => $this->departmentServiceTypeMap[$department] ?? [],
                    'tags'            => $this->departmentTagMap[$department] ?? [],
                    'url'             => '/about-us/',
                    'articleTypes'    => [],
                    'relatedArticles' => [],
                ],
            ];
        }

        return $chunks;
    }

    /**
     * Collects the company overview from the plain "text" elements on the page,
     * cleaned to plain text and joined into paragraphs.
     *
     * Elements that live inside a hidden container are skipped: the element's own
     * hidden flag stays 0 when an editor hides an ancestor container (e.g. to take
     * an old design variant offline), so the whole ancestry must be checked.
     */
    private function fetchCompanyOverview(): string
    {
        $qb = $this->createRestrictedQueryBuilder();

        $rows = $qb->select('bodytext', 'tx_container_parent')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter(self::STORAGE_PID, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('text')),
                $qb->expr()->neq('bodytext', $qb->createNamedParameter(''))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $paragraphs = [];
        foreach ($rows as $row) {
            if (!$this->isContainerChainVisible((int) $row['tx_container_parent'])) {
                continue;
            }

            $text = $this->htmlToText($this->stripCtaButtons((string) $row['bodytext']));
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * Walks the container ancestry upwards and returns false if any ancestor is
     * hidden or deleted. The restricted query builder applies hidden/deleted
     * restrictions, so a missing row means that ancestor is not visible.
     */
    private function isContainerChainVisible(int $parentUid): bool
    {
        $guard = 0;
        while ($parentUid > 0) {
            if (++$guard > 50) {
                // Defensive: bail out of an unexpected cyclic container nesting.
                return false;
            }

            $qb = $this->createRestrictedQueryBuilder();
            $row = $qb->select('tx_container_parent')
                ->from($this->contentTable)
                ->where(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($parentUid, ParameterType::INTEGER))
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($row === false) {
                return false;
            }

            $parentUid = (int) $row['tx_container_parent'];
        }

        return true;
    }

    /**
     * Resolves the live team members grouped by department.
     *
     * @return array<int, array{name: string, role: string, department: string}>
     */
    private function fetchTeamMembers(): array
    {
        $departmentHeaders = $this->fetchDepartmentHeaders();
        if ($departmentHeaders === []) {
            return [];
        }

        // All department headers live in the same parent container.
        $teamContainerUid = (int) $departmentHeaders[0]['tx_container_parent'];

        $members = [];
        foreach ($this->fetchFlexContainers($teamContainerUid) as $flexContainer) {
            $department = $this->departmentForSorting(
                $departmentHeaders,
                (int) $flexContainer['sorting']
            );
            if ($department === '') {
                continue;
            }

            foreach ($this->fetchTextpicChildren((int) $flexContainer['uid']) as $bodytext) {
                $parsed = $this->parseMember((string) $bodytext);
                if ($parsed === null) {
                    continue;
                }

                $members[] = [
                    'name'       => $parsed['name'],
                    'role'       => $parsed['role'],
                    'department' => $department,
                ];
            }
        }

        return $members;
    }

    /**
     * Returns the known department header elements with their sorting and parent.
     *
     * @return array<int, array{uid: int, header: string, sorting: int, tx_container_parent: int}>
     */
    private function fetchDepartmentHeaders(): array
    {
        $qb = $this->createRestrictedQueryBuilder();

        $rows = $qb->select('uid', 'header', 'sorting', 'tx_container_parent')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter(self::STORAGE_PID, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('header')),
                $qb->expr()->in(
                    'header',
                    $qb->createNamedParameter(array_keys($this->departmentServiceTypeMap), \Doctrine\DBAL\ArrayParameterType::STRING)
                )
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Returns the flex-containers directly inside the team container, ordered by
     * position on the page.
     *
     * @return array<int, array{uid: int, sorting: int}>
     */
    private function fetchFlexContainers(int $teamContainerUid): array
    {
        $qb = $this->createRestrictedQueryBuilder();

        return $qb->select('uid', 'sorting')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter(self::STORAGE_PID, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('flex-container')),
                $qb->expr()->eq('tx_container_parent', $qb->createNamedParameter($teamContainerUid, ParameterType::INTEGER))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns the bodytext of every textpic element directly inside a flex-container.
     *
     * @return array<int, string>
     */
    private function fetchTextpicChildren(int $flexContainerUid): array
    {
        $qb = $this->createRestrictedQueryBuilder();

        return $qb->select('bodytext')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter(self::STORAGE_PID, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('textpic')),
                $qb->expr()->eq('tx_container_parent', $qb->createNamedParameter($flexContainerUid, ParameterType::INTEGER))
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    /**
     * Picks the department header that most closely precedes the given sorting value.
     *
     * @param array<int, array{sorting: int, header: string}> $departmentHeaders
     */
    private function departmentForSorting(array $departmentHeaders, int $sorting): string
    {
        $department = '';
        foreach ($departmentHeaders as $header) {
            if ((int) $header['sorting'] <= $sorting) {
                $department = (string) $header['header'];
            }
        }

        return $department;
    }

    /**
     * Parses a textpic member block, e.g.
     *   <p><strong>Stevo O'Rourke</strong><br /><i>Director</i></p>
     *   <p><i><strong>Steph Miller&nbsp;</strong></i><br /><i>Producer, Director</i></p>
     *
     * @return array{name: string, role: string}|null
     */
    private function parseMember(string $bodytext): ?array
    {
        if (!preg_match('/<strong>(.*?)<\/strong>/si', $bodytext, $nameMatch)) {
            return null;
        }

        $name = $this->cleanInline($nameMatch[1]);
        if (strlen($name) < 2) {
            return null;
        }

        $role = '';
        if (preg_match_all('/<i>(.*?)<\/i>/si', $bodytext, $italics)) {
            foreach ($italics[1] as $candidate) {
                $text = $this->cleanInline($candidate);
                if ($text !== '' && $text !== $name) {
                    $role = $text;
                }
            }
        }

        if ($role === '') {
            return null;
        }

        return ['name' => $name, 'role' => $role];
    }

    /**
     * Strips inline tags, decodes entities and collapses whitespace for short
     * inline values like a name or role.
     */
    private function cleanInline(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\t", "\r", "\n"], ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function createRestrictedQueryBuilder()
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($this->contentTable);
        $qb->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        return $qb;
    }
}
