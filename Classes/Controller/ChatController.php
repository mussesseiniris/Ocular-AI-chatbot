<?php

namespace Ocular\Chatbot\Controller;

use Ocular\Chatbot\Service\ChatService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Ocular\Chatbot\Service\RateLimitService;
use Psr\Log\LoggerInterface;
use Ocular\Chatbot\Service\TurnstileService;

class ChatController extends ActionController
{

    private ChatService $chatService;
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;
    private const MAX_TURNS_KEPT = 6;
    private TurnstileService $turnstileService;

    public function __construct(ChatService $chatService, RateLimitService $rateLimitService, LoggerInterface $logger, TurnstileService $turnstileService)
    {

        $this->chatService = $chatService;
        $this->rateLimitService = $rateLimitService;
        $this->logger = $logger;
        $this->turnstileService = $turnstileService;
        
    }

    /**
     * Receives the user's question and returns the AI-generated answer as JSON
     *
     * @return ResponseInterface
     */
    public function askAction(): ResponseInterface
    {   
        $this->logger->debug('[ChatController] askAction called');
        $this->logger->debug('[ChatController] token argument: ' . ($this->request->hasArgument('turnstileToken') ? 'present' : 'MISSING'));
        $this->logger->debug('[ChatController] secret key set: ' . (empty(getenv('TURNSTILE_SECRET_KEY')) ? 'NO' : 'yes'));
        try {
            $ip = $this->request->getAttribute('normalizedParams')->getRemoteAddress();

            if (!$this->turnstileService->isConfigured()) {
                $this->logger->error('[Turnstile] TURNSTILE_SECRET_KEY is not set — blocking all requests');
                return $this->jsonResponse(json_encode([
                    'answer' => 'Service configuration error. Please contact us at results@ocular.nz.'
                ]));
            }

            $token = $this->request->hasArgument('turnstileToken')
                ? $this->request->getArgument('turnstileToken')
                : '';

            if (!$this->turnstileService->verify($token, $ip)) {
                $this->logger->warning('[Turnstile] Blocked request from IP: ' . ($ip ?? 'unknown'));
                return $this->jsonResponse(json_encode([
                    'answer' => 'Verification failed. Please try again.'
                ]))->withStatus(403);
            }

            if (!$this->request->hasArgument('question')) {
                return $this->htmlResponse($this->view->render());
            }
            
            // this->logger->debug('[ChatController] IP resolved as: ' . $ip);
            if (!$this->rateLimitService->isAllowed($ip)) {
                return $this->jsonResponse(json_encode([
                    'answer' => 'You have reached the daily question limit. Please try again tomorrow or contact us at results@ocular.nz for further help.'
                ]))->withStatus(429);
            }

            $question = $this->request->getArgument('question');
            $feuser = $this->request->getAttribute('frontend.user');
            $history = ($feuser !== null) ? ($feuser->getSessionData('chatbot_history') ?? []) : [];
            $result = $this->chatService->ask($question, $history);
            $history[] = ['role' => 'user', 'content' => $question];
            $history[] = ['role' => 'assistant', 'content' => $result];
            $history = array_slice($history, -self::MAX_TURNS_KEPT);
            if ($feuser !== null) {
                $feuser->setAndSaveSessionData('chatbot_history', $history);
            }
            return $this->jsonResponse(json_encode(['answer' => $result]));
        } catch (\Throwable $e) {
            $this->logger->error('[ChatController] ERROR: ' . $e->getMessage(),  [
                'exception' => $e,
            ]);
            return $this->jsonResponse(json_encode([
                'answer' => 'Something went wrong. Please try again.',
            ]))->withStatus(500);
        }
    }
    
      public function historyAction(): ResponseInterface
  {
      $feuser = $this->request->getAttribute('frontend.user');
      $history = ($feuser !== null) ? ($feuser->getSessionData('chatbot_history') ?? []) : [];
      return $this->jsonResponse(json_encode(['history' => $history]));
  }

}
