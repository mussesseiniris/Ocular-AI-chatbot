<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Http\Discovery\Psr17Factory;

class TurnstileService 
{
    private ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(ClientInterface $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function isConfigured(): bool
    {
        return !empty(getenv('TURNSTILE_SECRET_KEY'));
    }

    public function verify(string $token, string $ip): bool 
    {
        $secretKey = getenv('TURNSTILE_SECRET_KEY');

        if (empty($token)) {
            $this->logger->debug('[Turnstile] Empty token — verification failed');
            return false;
        }

        $factory = new Psr17Factory();
        $requestbody = http_build_query([
            'secret'   => $secretKey,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        $request = $factory->createRequest('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream($requestbody));

        try {
            $response = $this->client->sendRequest($request);

            $responsebody = $response->getBody()->getContents();
            $result = json_decode($responsebody, true);
            // error_log('[TURNSTILE DEBUG] ' . $body); // temporary

            $this->logger->debug('[Turnstile] Cloudflare response: ' . $responsebody);
            
            if (!($result['success'] ?? false)) {
                $this->logger->warning('[Turnstile] Verification failed', [
                    'error_codes' => $result['error-codes'] ?? [],
                ]);
            }

            return $result['success'] ?? false;
        } catch (\Throwable $e) {
            $this->logger->error('[Turnstile] HTTP request failed: ' . $e->getMessage());
            return false;
        }
    }
    
    
}