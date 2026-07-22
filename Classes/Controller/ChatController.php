<?php

namespace Ocular\Chatbot\Controller;

use Ocular\Chatbot\Service\ChatService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Ocular\Chatbot\Service\RateLimitService;
use Psr\Log\LoggerInterface;
use Ocular\Chatbot\Service\TurnstileService;
use Ocular\Chatbot\Service\InteractionLogService;

class ChatController extends ActionController
{

    private ChatService $chatService;
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;
    private const MAX_TURNS_KEPT = 6;
    private TurnstileService $turnstileService;
    private InteractionLogService $interactionLogService;

    public function __construct(ChatService $chatService, RateLimitService $rateLimitService, LoggerInterface $logger, TurnstileService $turnstileService, InteractionLogService $interactionLogService)
    {

        $this->chatService = $chatService;
        $this->rateLimitService = $rateLimitService;
        $this->logger = $logger;
        $this->turnstileService = $turnstileService;
        $this->interactionLogService = $interactionLogService;

    }

    /**
     * Receives the user's question and returns the AI-generated answer as JSON
     *
     * @return ResponseInterface
     */
    public function askAction(): ResponseInterface
    {   
        $this->logger->debug('[ChatController] askAction called');
        try {
            $ip = $this->request->getAttribute('normalizedParams')->getRemoteAddress();
            $resultsEmail = $this->settings['contact']['resultsEmail'] ?? 'results@ocular.nz';
            $feuser = $this->request->getAttribute('frontend.user');
            $sessionId = ($feuser !== null) ? $feuser->getSession()->getIdentifier() : '';
            $ipHash = $this->rateLimitService->hashIp($ip);

            if (!$this->turnstileService->isConfigured()) {
                $this->logger->error('[Turnstile] TURNSTILE_SECRET_KEY is not set — blocking all requests');
                return $this->jsonResponse(json_encode([
                    'answer' => "Service configuration error. Please contact us at {$resultsEmail}."
                ]))->withStatus(503);
            }

            $token = $this->request->hasArgument('turnstileToken')
                ? $this->request->getArgument('turnstileToken')
                : '';

            if (!$this->turnstileService->verify($token, $ip)) {
                $this->logger->warning('[Turnstile] Blocked request from IP: ' . ($ip ?? 'unknown'));
                $this->interactionLogService->log($sessionId, 0, 0, $ipHash,'', 'blocked');
                return $this->jsonResponse(json_encode([
                    'answer' => 'Verification failed. Please try again.'
                ]))->withStatus(403);
            }

            if (!$this->request->hasArgument('question')) {
                return $this->jsonResponse(json_encode([
                    'answer' => 'No question provided. Please input a question and try again.'
                ]))->withStatus(400);
            }

            // this->logger->debug('[ChatController] IP resolved as: ' . $ip);
            if (!$this->rateLimitService->isAllowed($ip)) {
                $this->interactionLogService->log($sessionId, 0, 0, $ipHash, '', 'rate_limited');
                return $this->jsonResponse(json_encode([
                    'answer' => "You have reached the daily question limit. Please try again tomorrow or contact us at {$resultsEmail} for further help."
                ]))->withStatus(429);
            }

            $question = $this->request->getArgument('question');
            $history = ($feuser !== null) ? ($feuser->getSessionData('chatbot_history') ?? []) : [];
            $turn = (int) (($feuser !== null) ? ($feuser->getSessionData('chatbot_turn') ?? 0) : 0) + 1;
            $result = $this->chatService->ask($question, $history);

            $status = !$result->ok
                ? 'error'
                : (mb_strlen($question) > 300 ? 'too_long' : 'success');
            $this->interactionLogService->log($sessionId, $turn, $result->chunksFound, $ipHash, $result->topTopic, $status);

            if (!$result->ok) {
                // Failure: report the error status, and isn't addced into
                // session history to bloat LLM context.
                return $this->jsonResponse(json_encode(['answer' => $result->message]))->withStatus(500);
            }
            $history[] = ['role' => 'user', 'content' => $question];
            $history[] = ['role' => 'assistant', 'content' => $result->message];
            $history = array_slice($history, -self::MAX_TURNS_KEPT);
            if ($feuser !== null) {
                $feuser->setAndSaveSessionData('chatbot_history', $history);
                $feuser->setAndSaveSessionData('chatbot_turn', $turn);
            }
            return $this->jsonResponse(json_encode(['answer' => $result->message]));
        } catch (\Throwable $e) {
            $this->logger->error('[ChatController] ERROR: ' . $e->getMessage(),  [
                'exception' => $e,
            ]);
            $this->interactionLogService->log($sessionId ?? '', $turn ?? 0, 0, $ipHash ?? '', '', 'error');
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
