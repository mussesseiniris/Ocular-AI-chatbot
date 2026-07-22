# Ocular Chatbot

TYPO3 v13 extension that adds a RAG (Retrieval-Augmented Generation) AI chatbot to the Ocular website. It answers visitor questions using content pulled from the site itself (projects, services, articles, about-us pages, and the positioning PDF), embedded into a vector database and retrieved at query time.

## How it works

1. **Ingest** (`chatbot:ingest` CLI command) — reads content from TYPO3 database records (and one PDF), splits it into chunks, generates embeddings via Voyage AI, and stores them in Qdrant.
2. **Auto-sync** — a DataHandler hook (ChunkSyncHook and ChunkSyncService) keeps Qdrant up to date as editors work in the backend, without needing a manual re-ingest:
   - Saving, hiding, or deleting a News record (project/article) re-embeds just that record.
   - Saving or deleting a content element on the About Us or Services page rebuilds that whole section (several small content elements combine into shared chunks, so a full section rebuild is simpler and safer than replacing individual rows).
   - Services is the fragile one: ServiceProvider matches content elements by a hardcoded list of exact service header names and by container position (gradient-container & sibling text element), not by a stable ID. Renaming a service header, restructuring its container, or adding a service outside that list can produce a missing or stale chunk. Treat Services as the section worth spot-checking after backend changes — re-run `chatbot:ingest --reset` if in doubt.
3. **Ask** — a visitor submits a question through the frontend widget. The backend:
   - Verifies the request with Cloudflare Turnstile.
   - Checks a per-IP daily rate limit.
   - Embeds the question and searches Qdrant for the most relevant chunks.
   - Builds a prompt (system prompt + retrieved chunks + recent conversation history) and sends it to an OpenAI-compatible LLM via LLPhant.
   - Returns the answer as JSON and stores the turn in the frontend user's session.
4. **History** — a separate endpoint returns the stored conversation history for the current session.

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
    ├── ChatResult.php              Wrapper of chat result with success or failure flag
    ├── ChatService.php              Search + prompt building + LLM call
    ├── QdrantIngester.php           Writes embedded chunks into Qdrant
    ├── RateLimitService.php         Per-IP daily question limit (DB-backed)
    └── TurnstileService.php         Cloudflare Turnstile verification
```

## Requirements

- TYPO3 ^13.0
- PHP with Composer
- A running [Qdrant](https://qdrant.tech/) instance
- A Voyage AI API key (embeddings)
- An OpenAI-compatible LLM endpoint (e.g. Groq) and API key

Composer dependencies: theodo-group/llphant, hkulekci/qdrant, smalot/pdfparser.

## Configuration

Set the following environment variables (e.g. in .env):

| Variable | Purpose |
|---|---|
| `VOYAGE_AI_API_KEY` | Voyage AI API key, used for embeddings |
| `OPENAI_API_KEY` | API key for the LLM endpoint |
| `LLM_API_URL` | Base URL of the OpenAI-compatible chat endpoint |
| `LLM_MODEL` | Model name to use for chat completions |
| `QDRANT_HOST` | Qdrant host |
| `QDRANT_PORT` | Qdrant port |
| `QDRANT_COLLECTION` | Qdrant collection name (chunks are stored under the openai named vector) |
| `RATE_LIMIT_SECRET` | Secret used to hash IPs before storing them for rate limiting. Secret also used to hash IPs for storing conversation in logging table|
| `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile secret key; requests are blocked if unset |
| `SITE_BASE_URL` | Public base URL of the site (e.g. https://ocular.nz), prepended to source links in answers |

The Turnstile site key is configured via TypoScript constant 'plugin.tx_chatbot_chatbot.turnstile.siteKey'.

The contact and support email chatbot refers to the user is configured via the TypoScript constant 'plugin.tx_chatbot_chatbot.contact.resultEmail' and 'plugin.tx_chatbot_chatbot.contact.supportEmail'

## Installation

`ocular-nz/chatbot` is already declared in site-ocular12 project's root `composer.json` (as dev-main, pulled from the mussesseiniris/Ocular-AI-chatbot VCS repository listed under repositories):

1. In site-ocular12 project run `composer update ocular-nz/chatbot` to pull the latest commit from dev-main (or `composer install` on a fresh checkout). Confirm it shows as active under Admin Tools > Extensions if in doubt. It also runs the database schema update so the 'tx_chatbot_rate_limit' and 'tx_chatbot_interaction_log' tables are created automatically at this step - no manual Database Compare needed. Only fall back to running it manually (Admin Tools > Maintenance > Analyze Database Structure, or `vendor/bin/typo3 database:updateschema`) if a deploy pipeline installs with '--no-scripts' and skips the hook.
2. Set the environment variables listed above.
3. Check the storage page IDs in the extension configuration (see below).
4. Run ingest command `vendor/bin/typo3 chatbot:ingest` to ingest content into qdrant 

To use this extension in a different project instead, add the git repository under repositories in that project's 'composer.json' and run `composer require ocular-nz/chatbot:dev-main` first.

### Storage Page IDs configuration

The storage page IDs the content providers read from are set in Admin Tools > Settings > Extension Configuration > chatbot:

| Setting | Purpose | Default |
|---|---|---|
| `aboutUsPid` | Page ID of the About Us page | 2 |
| `servicePid` | Page ID of the Services page | 6 |W
| `projectPid` | Storage folder ID of project news records | 12 |
| `articlePid` | Storage folder ID of article news records | 19 |

On a fresh install, verify these match the actual page tree. A wrong PID makes the corresponding source silently produce zero chunks.

## Usage

### Ingest content into Qdrant

```
vendor/bin/typo3 chatbot:ingest
```
Note - Connects to the already-running Qdrant container (started via docker-compose.yaml). By default, creates the collection only if it doesn't exist yet, then adds/updates chunks (existing chunks aren't removed, stale ones can linger). Use --reset to wipe the collection and rebuild it from scratch.

### Rate-limit records

```
vendor/bin/typo3 chatbot:cleanup-ratelimit
```

Deletes rate-limit rows older than 24 hours; intended to run on a schedule (already tagged schedulable).

## Interaction log records

```
vendor/bin/typo3 chatbot:cleanup-interactionlog
```
Deletes interaction log records older than the retention window (e.g. 90 days); intended to run on a schedule (already tagged schedulable).

## Frontend endpoints

- `chatbotAjax` (typeNum `1589`) → `ChatController::askAction` — accepts `question` and `turnstileToken`, returns `{ "answer": "..." }`.
- `chatbotHistory` (typeNum `1590`) → `ChatController::historyAction` — returns `{ "history": [...] }` for the current frontend session.

## Notes
- No manual step is needed to put the widget on a page. 'setup.typoscript' already injects the floating chat widget (CSS, JS, and the 'ChatWidget.html' partial) into the footer of every page, and the `chatbotAjax` / `chatbotHistory` typeNums work on any page ID. 
- The system prompt is loaded from Resources/Private/Prompts/SystemPrompt.md at request time.
- Conversation history is capped at the last 6 messages (3 question-answer exchanges) and stored in the frontend user's session.
- Questions are limited to 300 characters server-side. 
- This extension is at an early (`alpha`) stage.
