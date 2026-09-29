<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

/**
 * One model call that both rewrites a follow up and expands it for search.
 *
 * Before, a follow up cost two calls in a row: the rewrite (turn "what about
 * without an oven?" into "roast chicken without oven"), then Scolta's query
 * expansion on the result. Both only produce search terms, so this asks for
 * both at once: the site's own expansion prompt, prefixed with the rewrite
 * rules, answering {"query", "needs_search", "terms"}.
 *
 * The first turn has nothing to rewrite, so it still goes to Scolta's
 * expand-query endpoint, which caches by query.
 *
 * Framework free: shared by the Drupal module and the WordPress plugin.
 */
final class QueryPlan {

  public const MAX_TERMS = 6;

  /**
   * The system prompt for the combined call.
   *
   * @param string $siteName
   *   The site name.
   * @param string $expandPrompt
   *   The site's resolved Scolta expansion prompt (getExpandPrompt()).
   */
  public static function systemPrompt(string $siteName, string $expandPrompt): string {
    $site = $siteName !== '' ? $siteName : 'the website';
    return "You prepare the site search for a conversation with the search assistant of {$site}. Do two things in one reply.\n\n"
      . "FIRST, turn the latest visitor message into one standalone search query:\n"
      . "- Resolve pronouns and ellipsis from the earlier turns. \"What about without an oven?\" after a question about roast chicken becomes \"roast chicken without oven\".\n"
      . "- When the latest message changes topic, search the new topic only and drop the old one.\n"
      . "- Keep the query short: the key terms, names and entities, no filler, at most 12 words.\n"
      . "- Never answer the question.\n"
      . "- Set needs_search to false only for greetings, thanks, small talk, or remarks about the conversation itself that need no facts from the site. Anything that asks for information needs a search, and then the query must not be empty.\n\n"
      . "SECOND, expand that standalone query for the site search, following the expansion instructions below exactly. When needs_search is false, return an empty terms list.\n\n"
      . "Reply with one JSON object only, no prose and no code fence: {\"query\": \"...\", \"needs_search\": true, \"terms\": [\"...\"]}. The expansion instructions below describe a reply with only a \"terms\" key; add \"query\" and \"needs_search\" to that same object.\n\n"
      . "EXPANSION INSTRUCTIONS:\n"
      . $expandPrompt;
  }

  /**
   * The user message for the combined call.
   *
   * @param array<int, array{role: string, content: string}> $history
   */
  public static function userMessage(string $message, array $history): string {
    $lines = ['Earlier in the conversation:'];
    foreach ($history as $item) {
      $lines[] = ($item['role'] === 'user' ? 'Visitor: ' : 'Assistant: ') . $item['content'];
    }
    $lines[] = '';
    $lines[] = 'Latest visitor message: ' . $message;
    return implode("\n", $lines);
  }

  /**
   * Parses the model's JSON reply, tolerating a code fence around it.
   *
   * @return array{query: string, needs_search: bool, terms: string[]}|null
   *   The plan, or NULL when the reply is unusable.
   */
  public static function parse(string $raw): ?array {
    if (!preg_match('/\{.*\}/s', $raw, $match)) {
      return NULL;
    }
    $decoded = json_decode($match[0], TRUE);
    if (!is_array($decoded) || !is_string($decoded['query'] ?? NULL)) {
      return NULL;
    }
    $query = trim(preg_replace('/\s+/u', ' ', $decoded['query']) ?? '');
    $needsSearch = ($decoded['needs_search'] ?? TRUE) !== FALSE;
    if ($query === '' && $needsSearch) {
      return NULL;
    }
    $terms = [];
    foreach (is_array($decoded['terms'] ?? NULL) ? $decoded['terms'] : [] as $term) {
      if (!is_string($term)) {
        continue;
      }
      $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
      if ($term !== '' && mb_strlen($term) <= 100 && strcasecmp($term, $query) !== 0 && !in_array($term, $terms, TRUE)) {
        $terms[] = $term;
      }
      if (count($terms) >= self::MAX_TERMS) {
        break;
      }
    }
    return [
      'query' => mb_substr($query, 0, 200),
      'needs_search' => $needsSearch,
      'terms' => $needsSearch ? $terms : [],
    ];
  }

}
