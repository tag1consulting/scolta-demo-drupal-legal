# Scolta chat (proof of concept)

Turns the Drupal AI chatbot (`ai_chatbot`, the DeepChat block) into a
conversation grounded in Scolta search. Every turn searches the site, the
answer cites those results, and the results are listed under the answer.

## How a turn runs

1. **Browser** (`js/scolta-chat.js`, wrapping the request interceptor that
   `deepchat-init.js` sets up): rewrites the turn into a standalone query
   (`POST /scolta-chat-poc/rewrite`, skipped on the first turn), expands it
   (`/api/scolta/v1/expand-query`), searches Pagefind, re-ranks with the
   scolta-core WebAssembly module, keeps the best five, extracts context
   within the character budget, and adds it all to the request as `scolta`.
2. **Server** (`scolta_grounded` chat processor): keeps the thread in the
   private tempstore (the client's copy of the thread is ignored), folds old
   turns into a running summary, builds the prompt from Scolta's follow up
   grounding rules plus a chat register, calls `scolta.ai_service`, and
   appends the escaped result list after DeepChat's Xss filter.

Context stays bounded without a turn cap: the last 6 messages verbatim, a
running summary of at most 120 words for everything older, a 12,000
character backstop, excerpts for the current turn only, and earlier cited
pages as a short title and URL list. A 40 turn ceiling guards runaways.

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
