<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Provider;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendGroupRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds chatbot chunks for the Services page straight from the database.
 *
 * Replaces the HTTP-scraping ServiceCrawler. On the Services page (pid 6) each
 * service is authored as a "text" content element whose header is the service
 * name (e.g. "Digital Platforms"); that header element lives inside a
 * gradient-container, and the matching description is a sibling "text" element
 * (no header) under the gradient-container's parent container.
 */
class ServiceProvider
{
    use HtmlToTextTrait;

    private int $STORAGE_PID;

    private string $contentTable = 'tt_content';
    
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->STORAGE_PID = (int)$extensionConfiguration->get('chatbot', 'servicePid');
    }


    /**
     * Maps the 3 visible services to the 3 categories used on the projects list page.
     * The keys also act as the authoritative list of service headers to look up.
     */
    private array $serviceToServiceTypeMap = [
        'Digital Platforms'       => 'Platforms',
        'Content & Communication' => 'Communication',
        'UX & Experience Design'  => 'Experiences',
        'Systems & Integration'   => 'Platforms',
        'Emerging Technology'     => '',
    ];

    /**
     * Services mapped to related tags, using the same tag vocabulary as projects.
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
     * Maps service categories to their related process article entity IDs, so the
     * LLM can point users to further reading. Kept in sync with the IDs the article
     * sources generate. Emerging Technology is omitted — no process article exists.
     */
    private array $serviceProcessArticleMap = [
        'UX & Experience Design'  => 'article_process_design',
        'Digital Platforms'       => 'article_process_online',
        'Systems & Integration'   => 'article_process_online',
        'Content & Communication' => 'article_process_video',
    ];

    /**
     * @return array List of chunks with 'content' and 'metadata'
     */
    public function buildChunks(): array
    {
        $chunks = [];

        foreach ($this->fetchServices() as $service) {
            $name = $service['name'];

            $description = $this->htmlToText($this->stripCtaButtons((string) $service['description']));
            if ($description === '') {
                continue;
            }

            $serviceType = $this->serviceToServiceTypeMap[$name] ?? '';
            $processArticleId = $this->serviceProcessArticleMap[$name] ?? null;

            $chunks[] = [
                'content'  => $name . "\n\n" . $description,
                'metadata' => [
                    'name'            => $name,
                    'entityType'      => 'service',
                    'entityId'        => 'service_' . strtolower($name),
                    'entityName'      => $name,
                    'chunk_type'      => 'service_overview',
                    'serviceTypes'    => $serviceType !== '' ? [$serviceType] : [],
                    'tags'            => $this->serviceToTagsMap[$name] ?? [],
                    'url'             => '/services/',
                    'articleTypes'    => [],
                    'relatedArticles' => $processArticleId !== null ? [$processArticleId] : [],
                ],
            ];
        }

        return $chunks;
    }

    /**
     * Resolves each known service name to its description text.
     *
     * @return array<int, array{name: string, description: string}>
     */
    private function fetchServices(): array
    {
        $services = [];

        foreach (array_keys($this->serviceToServiceTypeMap) as $name) {
            $nameElement = $this->fetchServiceNameElement($name);
            if ($nameElement === null) {
                continue;
            }

            // header element -> gradient-container -> outer container that also holds the description
            $gradientContainerUid = (int) $nameElement['tx_container_parent'];
            $outerContainerUid = $this->fetchParentContainerUid($gradientContainerUid);
            if ($outerContainerUid === null) {
                continue;
            }

            $description = $this->fetchDescriptionInContainer(
                $outerContainerUid,
                (int) $nameElement['uid']
            );

            $services[] = [
                'name'        => $name,
                'description' => $description,
            ];
        }

        return $services;
    }

    /**
     * Finds the "text" content element on the services page whose header is the
     * given service name.
     *
     * @return array{uid: int, tx_container_parent: int}|null
     */
    private function fetchServiceNameElement(string $serviceName): ?array
    {
        $qb = $this->createRestrictedQueryBuilder();

        $row = $qb->select('uid', 'tx_container_parent')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($this->STORAGE_PID, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('text')),
                $qb->expr()->eq('header', $qb->createNamedParameter($serviceName))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Returns the tx_container_parent of the given content element, or null if it
     * has no parent container.
     */
    private function fetchParentContainerUid(int $uid): ?int
    {
        $qb = $this->createRestrictedQueryBuilder();

        $parent = $qb->select('tx_container_parent')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $parent ? (int) $parent : null;
    }

    /**
     * Finds the description: the first "text" element (other than the name header)
     * directly inside the given container that carries body text.
     */
    private function fetchDescriptionInContainer(int $containerUid, int $excludeUid): string
    {
        $qb = $this->createRestrictedQueryBuilder();

        $bodytext = $qb->select('bodytext')
            ->from($this->contentTable)
            ->where(
                $qb->expr()->eq('tx_container_parent', $qb->createNamedParameter($containerUid, ParameterType::INTEGER)),
                $qb->expr()->eq('CType', $qb->createNamedParameter('text')),
                $qb->expr()->neq('uid', $qb->createNamedParameter($excludeUid, ParameterType::INTEGER)),
                $qb->expr()->neq('bodytext', $qb->createNamedParameter(''))
            )
            ->orderBy('sorting')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $bodytext ? (string) $bodytext : '';
    }

    private function createRestrictedQueryBuilder()
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($this->contentTable);
        $qb->getRestrictions()->add(GeneralUtility::makeInstance(FrontendGroupRestriction::class));
        return $qb;
    }
}
