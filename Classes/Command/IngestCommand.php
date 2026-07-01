<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Command;

use Ocular\Chatbot\Crawler\PositioningPdfCrawler;
use Ocular\Chatbot\Domain\Model\ChunkDocument;
use Ocular\Chatbot\Provider\AboutUsProvider;
use Ocular\Chatbot\Provider\ArticleProvider;
use Ocular\Chatbot\Provider\ProjectProvider;
use Ocular\Chatbot\Provider\ServiceProvider;
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

    /**
     * Content sources keyed by name. Each exposes buildChunks(). Projects,
     * articles, services and about-us read straight from the database; positioning
     * still scrapes the PDF as it has no database source.
     */
    private array $sources;

    public function __construct(
        ProjectProvider $projectProvider,
        AboutUsProvider $aboutUsProvider,
        ArticleProvider $articleProvider,
        ServiceProvider $serviceProvider,
        PositioningPdfCrawler $positioningPdfCrawler,
        Voyage4EmbeddingGenerator $embeddingGenerator,
        QdrantIngester $qdrantIngester,
        LoggerInterface $logger
    ) {
        parent::__construct();

        $this->embeddingGenerator = $embeddingGenerator;
        $this->qdrantIngester = $qdrantIngester;
        $this->logger = $logger;

        $this->sources = [
            'projects'    => $projectProvider,
            'about-us'    => $aboutUsProvider,
            'articles'    => $articleProvider,
            'services'    => $serviceProvider,
            'positioning' => $positioningPdfCrawler,
        ];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reads Ocular content sources, embeds the chunks via Voyage AI, and ingests them into Qdrant.')
            ->addOption(
                'source',
                's',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of sources to run: projects, about-us, articles, services, positioning, or "all" (default)',
                'all'
            )
            ->addOption(
                'reset',
                'r',
                InputOption::VALUE_NONE,
                'Delete the Qdrant collection and recreate it (empty) before ingesting. WARNING: wipes all existing vectors.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requested = (string) $input->getOption('source');
        $names = $requested === 'all'
            ? array_keys($this->sources)
            : array_map('trim', explode(',', $requested));

        $selected = [];
        foreach ($names as $name) {
            if (!isset($this->sources[$name])) {
                $output->writeln(sprintf(
                    '<error>Unknown source "%s". Available: %s</error>',
                    $name,
                    implode(', ', array_keys($this->sources))
                ));
                return Command::INVALID;
            }
            $selected[] = $this->sources[$name];
        }

        $output->writeln('Testing Voyage AI connection...');
        $testEmbedding = $this->embeddingGenerator->embedText('test');
        if (empty($testEmbedding)) {
            $this->logger->error('[IngestCommand] Voyage AI connection test failed — empty embedding returned.');
            $output->writeln('<error>Voyage AI returned an empty embedding. Check VOYAGE_AI_API_KEY.</error>');
            return Command::FAILURE;
        }
        $embeddingLength = count($testEmbedding);
        $output->writeln('Embedding length: ' . $embeddingLength);

        if ($input->getOption('reset')) {
            $output->writeln('<comment>Resetting Qdrant collection (deleting existing data and recreating)...</comment>');
            $this->qdrantIngester->recreateCollection($embeddingLength);
            $output->writeln('Collection recreated (empty).');
        } else {
            // Self-heal: create the collection on first run if it's missing.
            $this->qdrantIngester->ensureCollectionExists($embeddingLength);
        }

        // Phase 1: build all chunks from every selected source first (no embedding yet).
        $allChunks = [];
        foreach ($selected as $source) {
            $sourceName = (new \ReflectionClass($source))->getShortName();
            $output->writeln("\nReading from {$sourceName}...");

            $chunks = $source->buildChunks();
            $output->writeln('Found ' . count($chunks) . ' chunks');

            foreach ($chunks as $chunk) {
                $allChunks[] = $chunk;
            }
        }

        $total = count($allChunks);
        $output->writeln("\nCollected {$total} chunks from all sources. Starting embedding...");

        // Phase 2: embed and ingest every collected chunk.
        foreach ($allChunks as $index => $chunk) {
            $metadata = $chunk['metadata'];

            $output->writeln(sprintf(
                'Embedding chunk %d of %d: %s (%s)',
                $index + 1,
                $total,
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

            // embedDocument() mutates $doc in place (sets ->embedding) and returns
            // it as the parent Document type; not reassigning keeps $doc typed as
            // ChunkDocument so its custom properties (chunkId, etc.) stay resolvable.
            $this->embeddingGenerator->embedDocument($doc);

            if (empty($doc->embedding)) {
                $this->logger->error('[IngestCommand] Empty embedding for chunk', ['chunk_id' => $doc->chunkId]);
                $output->writeln("<error>Empty embedding for chunk: {$doc->chunkId}</error>");
                return Command::FAILURE;
            }

            $this->qdrantIngester->addDocument($doc);
        }

        $output->writeln("\nAll sources complete!");
        return Command::SUCCESS;
    }
}