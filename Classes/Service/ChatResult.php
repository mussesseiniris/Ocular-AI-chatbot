<?php

declare(strict_types=1);

namespace Ocular\Chatbot\Service;

/**
 * Result of ChatService::ask().
 *
 * Wraps the answer text together with a success flag so the controller can
 * tell a genuine assistant answer apart from a failure message — and decide
 * the HTTP status code and whether to persist the turn into session history
 * accordingly. ChatService has no business building HTTP responses itself;
 * this keeps that decision in the controller where it belongs.
 */
final class ChatResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly int $chunksFound = 0,
        public readonly string $topTopic = '',
    ) {
    }

    public static function success(string $answer, int $chunksFound = 0, string $topTopic = ''): self
    {
        return new self(true, $answer, $chunksFound, $topTopic);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}