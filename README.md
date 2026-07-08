# Ocular Chatbot

TYPO3 v13 extension that adds a RAG (Retrieval-Augmented Generation) AI chatbot to the Ocular website. It answers visitor questions using content pulled from the site itself (projects, services, articles, about-us pages, and the positioning PDF), embedded into a vector database and retrieved at query time.

## How it works

1. **Ingest** (`chatbot:ingest` CLI command) — reads content from TYPO3 database records (and one PDF), splits it into chunks, generates embeddings via **Voyage AI**, and stores them in **Qdrant**.
2. **Ask** — a visitor submits a question through the frontend widget. The backend:
   - Verifies the request with **Cloudflare Turnstile**.
   - Checks a per-IP daily rate limit.
   - Embeds the question and searches Qdrant for the most relevant chunks.
   - Builds a prompt (system prompt + retrieved chunks + recent conversation history) and sends it to an OpenAI-compatible LLM via **LLPhant**.
   - Returns the answer as JSON and stores the turn in the frontend user's session.
3. **History** — a separate endpoint returns the stored cogotnversation history for the current session.

## Architecture

```
Classes/
├── Command/
│   ├── IngestCommand.php            chatbot:ingest — embeds & ingests content into Qdrant
│   └── CleanupRateLimitCommand.php  chatbot:cleanup-ratelimit — purges rate-limit rows older than 24h
├── Controller/
│   └── ChatController.php           ask / history actions (JSON endpoints)
├── Provider/                        Builds content chunks from TYPO3 DB records
│   ├── AboutUsProvider.php
│   ├── ArticleProvider.php
│   ├── ProjectProvider.php
│   ├── ServiceProvider.php
│   ├── NewsContentProvider.php
│   └── HtmlToTextTrait.php
├── Crawler/                         Legacy/PDF sources (no DB equivalent)
│   ├── PositioningPdfCrawler.php
├── Embeddings/
│   └── Voyage4EmbeddingGenerator.php  Voyage AI (voyage-4) embedding client
└── Service/
    ├── ChatService.php              Search + prompt building + LLM call
    ├── QdrantIngester.php           Writes embedded chunks into Qdrant
    ├── RateLimitService.php         Per-IP daily question limit (DB-backed)
    └── TurnstileService.php         Cloudflare Turnstile verification
```

## Requirements

- TYPO3 `^13.0`
- PHP with Composer
- A running [Qdrant](https://qdrant.tech/) instance
- A Voyage AI API key (embeddings)
- An OpenAI-compatible LLM endpoint (e.g. Groq) and API key

Composer dependencies: `theodo-group/llphant`, `hkulekci/qdrant`, `smalot/pdfparser`, `symfony/dom-crawler`.

## Configuration

Set the following environment variables (e.g. in `.env`):

| Variable | Purpose |
|---|---|
| `VOYAGE_AI_API_KEY` | Voyage AI API key, used for embeddings |
| `OPENAI_API_KEY` | API key for the LLM endpoint |
| `LLM_API_URL` | Base URL of the OpenAI-compatible chat endpoint |
| `LLM_MODEL` | Model name to use for chat completions |
| `QDRANT_HOST` | Qdrant host |
| `QDRANT_PORT` | Qdrant port |
| `QDRANT_COLLECTION` | Qdrant collection name (chunks are stored under the `openai` named vector) |
| `RATE_LIMIT_SECRET` | Secret used to hash IPs before storing them for rate limiting |
| `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile secret key; requests are blocked if unset |

The Turnstile **site key** is configured via TypoScript constant `plugin.tx_chatbot_chatbot.turnstile.siteKey`.

## Installation

1. Require the extension via Composer and activate it in the TYPO3 backend.
2. Run the database compare (Admin Tools > Maintenance) to create the `tx_chatbot_rate_limit` table.
3. Set the environment variables listed above.
4. Add the `Chatbot` and `History` plugins to a page, or rely on the `chatbotAjax` / `chatbotHistory` page types wired up in TypoScript for the JS widget.

## Usage

### Ingest content into Qdrant

```
vendor/bin/typo3 chatbot:ingest
```

Options:
- `--source|-s` — comma-separated list of sources to run: `projects`, `about-us`, `articles`, `services`, `positioning`, or `all` (default).
- `--reset|-r` — deletes and recreates the Qdrant collection before ingesting (wipes existing vectors).

### Clean up expired rate-limit records

```
vendor/bin/typo3 chatbot:cleanup-ratelimit
```

Deletes rate-limit rows older than 24 hours; intended to run on a schedule (already tagged `schedulable`).

## Frontend endpoints

- `chatbotAjax` (typeNum `1589`) → `ChatController::askAction` — accepts `question` and `turnstileToken`, returns `{ "answer": "..." }`.
- `chatbotHistory` (typeNum `1590`) → `ChatController::historyAction` — returns `{ "history": [...] }` for the current frontend session.

## Notes

- The system prompt is loaded from `Resources/Private/Prompts/SystemPrompt.md` at request time.
- Conversation history is capped at the last 6 turns and stored in the frontend user's session.
- This extension is at an early (`alpha`) stage.
