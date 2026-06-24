<?php
require_once '/var/www/html/vendor/autoload.php';

use Qdrant\Models\Filter\Condition\MatchAny;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\VectorStruct;
use Ocular\Chatbot\Embeddings\Voyage4EmbeddingGenerator;
use Qdrant\Config;
use Qdrant\Http\Transport;
use Http\Discovery\Psr18ClientDiscovery;
use Qdrant\Qdrant;

$gen = new Voyage4EmbeddingGenerator();
$vec = $gen->embedText('Is there any example projects of web development?');
echo "Embedding length: " . count($vec) . "\n";

$filter = new Filter();
$filter->addMust(new MatchAny('service_types', ['Platforms']));
$filter->addMust(new MatchAny('tags', ['Web Development']));

$searchRequest = (new SearchRequest(new VectorStruct($vec, 'openai')))
    ->setLimit(3)
    ->setWithPayload(true);
$searchRequest->setFilter($filter);

$body = json_encode($searchRequest->toArray(), JSON_THROW_ON_ERROR);
echo "Body length: " . strlen($body) . "\n";

$config = new Config('qdrant', 6333);
$transport = new Transport(Psr18ClientDiscovery::find(), $config);
$client = new Qdrant($transport);

$response = $client->collections('ocular_chunks')->points()->search($searchRequest);
echo substr(json_encode($response->__toArray()), 0, 300) . "\n";
