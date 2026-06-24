<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Command;

use Ocular\Chatbot\Crawler\AboutUsCrawler;
use Ocular\Chatbot\Crawler\ArticlesCrawler;
use Ocular\Chatbot\Crawler\PositioningPdfCrawler;
use Ocular\Chatbot\Crawler\ProjectsCrawler;
use Ocular\Chatbot\Crawler\ServiceCrawler;
use Ocular\Chatbot\Domain\Model\ChunkDocument;
use Ocular\Chatbot\Embeddings\Voyage4EmbeddingGenerator;
use Ocular\Chatbot\Service\QdrantIngester;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IngestCommand extends Command
{
    private Voyage4EmbeddingGenerator $embeddingGenerator;
    private QdrantIngester $qdrantIngester;
    private LoggerInterface $logger;

    private array $crawlers;

    public function __construct(
        ProjectsCrawler $projectsCrawler,
        AboutUsCrawler $aboutUsCrawler,
        ArticlesCrawler $articlesCrawler,
        ServiceCrawler $serviceCrawler,
        PositioningPdfCrawler $positioningPdfCrawler,
        Voyage4EmbeddingGenerator $embeddingGenerator,
        QdrantIngester $qdrantIngester,
        LoggerInterface $logger
    ) {
        parent::__construct();

        $this->embeddingGenerator = $embeddingGenerator;
        $this->qdrantIngester = $qdrantIngester;
        $this->logger = $logger;

        $this->crawlers = [
            'projects'    => $projectsCrawler,
            'about-us'    => $aboutUsCrawler,
            'articles'    => $articlesCrawler,
            'services'    => $serviceCrawler,
            'positioning' => $positioningPdfCrawler,
        ];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Crawls Ocular content sources, embeds the chunks via Voyage AI, and ingests them into Qdrant.')
            ->addOption(
                'crawler',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of crawlers to run: projects, about-us, articles, services, positioning, or "all" (default)',
                'all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requested = (string) $input->getOption('crawler');
        $names = $requested === 'all'
            ? array_keys($this->crawlers)
            : array_map('trim', explode(',', $requested));

        $selected = [];
        foreach ($names as $name) {
            if (!isset($this->crawlers[$name])) {
                $output->writeln(sprintf(
                    '<error>Unknown crawler "%s". Available: %s</error>',
                    $name,
                    implode(', ', array_keys($this->crawlers))
                ));
                return Command::INVALID;
            }
            $selected[] = $this->crawlers[$name];
        }

        $output->writeln('Testing Voyage AI connection...');
        $testEmbedding = $this->embeddingGenerator->embedText('test');
        if (empty($testEmbedding)) {
            $this->logger->error('[IngestCommand] Voyage AI connection test failed — empty embedding returned.');
            $output->writeln('<error>Voyage AI returned an empty embedding. Check VOYAGE_AI_API_KEY.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Embedding length: ' . count($testEmbedding));

        foreach ($selected as $crawler) {
            $crawlerName = (new \ReflectionClass($crawler))->getShortName();
            $output->writeln("\nCrawling with {$crawlerName}...");

            $chunks = $crawler->buildChunks();
            $output->writeln('Found ' . count($chunks) . ' chunks');

            foreach ($chunks as $index => $chunk) {
                $metadata = $chunk['metadata'];

                $output->writeln(sprintf(
                    'Embedding chunk %d of %d: %s (%s)',
                    $index + 1,
                    count($chunks),
                    $metadata['entityName'] ?? '(unnamed)',
                    $metadata['chunk_type'] ?? ''
                ));

                $doc = new ChunkDocument();
                $doc->content = $chunk['content'];
                $doc->embeddingText = $chunk['content'];
                $doc->chunkId = 'chunk_' . strtolower(str_replace(' ', '_', $metadata['entityId'])) . '_' . $metadata['chunk_type'];
                $doc->entityId = $metadata['entityId'];
                $doc->entityType = $metadata['entityType'];
                $doc->entityName = $metadata['entityName'];
                $doc->serviceTypes = $metadata['serviceTypes'];
                $doc->tags = $metadata['tags'];
                $doc->chunkType = $metadata['chunk_type'];
                $doc->sourceName = $metadata['url'];
                $doc->articleTypes = $metadata['articleTypes'];
                $doc->relatedArticles = $metadata['relatedArticles'];
                $doc->url = $metadata['url'];

                if (empty(trim($doc->content))) {
                    $output->writeln("SKIPPING: empty content for chunk {$doc->chunkId}");
                    continue;
                }

                $doc = $this->embeddingGenerator->embedDocument($doc);

                if (empty($doc->embedding)) {
                    $this->logger->error('[IngestCommand] Empty embedding for chunk', ['chunk_id' => $doc->chunkId]);
                    $output->writeln("<error>Empty embedding for chunk: {$doc->chunkId}</error>");
                    return Command::FAILURE;
                }

                $this->qdrantIngester->addDocument($doc);

                // Voyage AI free-tier rate limit 
                sleep(21);
            }

            $output->writeln("Done with {$crawlerName}!");
        }

        $output->writeln("\nAll crawlers complete!");
        return Command::SUCCESS;
    }
}