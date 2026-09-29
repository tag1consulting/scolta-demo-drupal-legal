# Scolta chat (proof of concept)

Turns the Drupal AI chatbot (`ai_chatbot`, the DeepChat block) into a
conversation grounded in Scolta search. Every turn searches the site, the
answer cites those results, and the results are listed under the answer,
collapsed behind a "Sources" toggle. The view stays at the top of each reply.

## How a turn runs

1. **Browser** (`js/scolta-chat.js`, wrapping the request interceptor that
   `deepchat-init.js` sets up): on a follow up, one planning call
   (`POST /scolta-chat-poc/rewrite`) rewrites the turn into a standalone
   query and expands it; on a first turn, Scolta's expand-query endpoint
   expands the message (cached by query). Then it searches Pagefind
   (remembering repeated searches for the life of the page), re-ranks with
   the scolta-core WebAssembly module, keeps the best five, extracts context
   within the character budget, and adds it all to the request as `scolta`.
2. **Server** (`scolta_grounded` chat processor): keeps the thread in the
   private tempstore (the client's copy of the thread is ignored), builds
   the prompt from Scolta's follow up grounding rules plus a chat register,
   and answers through Scolta's provider settings. With the block's
   streaming option on, the answer streams as the provider writes it
   (`StreamingClient`); the escaped result list follows. An opening turn's
   answer is cached for Scolta's AI cache lifetime.
3. **After the reply**, the browser posts `/scolta-chat-poc/fold`, which
   folds turns that left the window into the running summary. It runs in a
   request of its own, so no answer waits for it.

Context stays bounded without a turn cap: the last 6 messages verbatim, a
running summary of at most 120 words for everything older, a 12,000
character backstop, excerpts for the current turn only, and earlier cited
pages as a short title and URL list. A 40 turn ceiling guards runaways.

Streaming needs the block's "Stream" option (`settings.stream: 1` in the
block config). It streams directly from Anthropic or an OpenAI compatible
endpoint (Amazee included); with Drupal AI as Scolta's provider it falls
back to one piece. Streamed links pass the module's own site-only link
filter: Drupal AI's stream filter is bypassed because in 1.5 it drops the
closing parenthesis of every allowed Markdown link.

Settings: `/admin/config/search/scolta/chat-poc`.

## Try it locally

    ddev start                  # imports the dump and config/sync
    ddev launch /search

Open "Ask ComplianceIQ" at the bottom right of any page except the front
page, or run a search and type a question into the follow up box under the
AI overview: it continues in the chat, seeded with that search.

The AI calls use the site's Scolta provider and key (`SCOLTA_API_KEY`).

## Tests

    ddev exec vendor/bin/phpunit web/modules/custom/scolta_chat_poc
