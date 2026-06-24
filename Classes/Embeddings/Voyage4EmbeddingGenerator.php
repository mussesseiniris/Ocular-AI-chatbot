<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Embeddings;

use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\AbstractVoyageAIEmbeddingGenerator;
use LLPhant\VoyageAIConfig;

final class Voyage4EmbeddingGenerator extends AbstractVoyageAIEmbeddingGenerator
{
    public function __construct()
    {
        parent::__construct(new VoyageAIConfig());
    }

    public function embedText(string $text): array
    {
        $text = str_replace("\n", ' ', $text);

        $body = json_encode([
            'model' => $this->getModelName(),
            'input' => [$text],  // wrap in array
            'truncation' => $this->truncate,
        ]);

        $factory = new \Http\Discovery\Psr17Factory();
        $request = $factory->createRequest('POST', $this->uri)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($factory->createStream($body));

        $response = $this->client->sendRequest($request);
        $json = json_decode($response->getBody()->getContents(), true);

        // echo "Status: " . $response->getStatusCode() . "\n";
        // echo "Response: " . json_encode($json) . "\n";

        return $json['data'][0]['embedding'] ?? [];
    }

    public function getEmbeddingLength(): int
    {
        return 1024;
    }

    public function getModelName(): string
    {
        return 'voyage-4';
    }
}