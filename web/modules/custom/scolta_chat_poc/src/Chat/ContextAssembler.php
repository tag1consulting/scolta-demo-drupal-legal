<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

/**
 * Decides what of a thread the model sees on each turn.
 *
 * Three layers keep the prompt small without capping the number of turns:
 *
 * - the last N messages travel verbatim (the window);
 * - everything older is folded into one running summary of at most M words,
 *   refreshed by one model call each time the window slides;
 * - a character backstop over the assembled list applies the rule of
 *   scolta-core's truncate_conversation (keep the first two messages, drop
 *   the oldest pairs after them), except that the current turn is never
 *   dropped: the rule as written would drop it last, which would send the
 *   model a conversation with no question in it.
 *
 * Search excerpts ride on the current turn only. Pages cited on earlier
 * turns travel as a short list of title and URL (prior sources).
 */
final class ContextAssembler {

  /**
   * How many earlier cited pages travel with each turn.
   */
  public const PRIOR_SOURCES_LIMIT = 8;

  public function __construct(
    private readonly int $windowMessages = 6,
    private readonly int $summaryWords = 120,
    private readonly int $historyChars = 12000,
  ) {}

  /**
   * Writes a search follow up into an empty thread as its opening exchange.
   *
   * The search query becomes the user turn and the AI summary the assistant
   * turn, which is how scolta.js seeds its own follow ups. The seed's result
   * pages join the prior sources so the model can refer back to them.
   *
   * @param array{query: string, summary: string, results: array} $seed
   *   The sanitized seed.
   *
   * @return bool
   *   TRUE when the seed was written.
   */
  public function applySeed(ThreadState $state, array $seed): bool {
    if ($state->messages !== [] || $seed['query'] === '' || $seed['summary'] === '') {
      return FALSE;
    }
    $state->addMessage('user', $seed['query']);
    $state->addMessage('assistant', $seed['summary']);
    foreach ($seed['results'] as $result) {
      $state->citedSources[$result['url']] = ['title' => $result['title'], 'url' => $result['url']];
    }
    $state->seeded = TRUE;
    return TRUE;
  }

  /**
   * Returns the messages that left the window but are not yet summarized.
   *
   * @return array<int, array{role: string, content: string, timestamp: int}>
   *   Oldest first; empty when the window has not slid since the last fold.
   */
  public function pendingFold(ThreadState $state): array {
    $outside = max(0, count($state->messages) - $this->windowMessages);
    if ($outside <= $state->folded) {
      return [];
    }
    return array_slice($state->messages, $state->folded, $outside - $state->folded);
  }

  /**
   * Builds the one model call that refreshes the running summary.
   *
   * @return array{system: string, user: string}
   *   The system prompt and user message for the fold call.
   */
  public function foldPrompt(ThreadState $state, array $evicted): array {
    $system = 'You maintain a running summary of a conversation between a visitor and a website search assistant. '
      . 'Merge the earlier summary with the messages that just left the conversation window. '
      . 'Keep what later turns may need: what the visitor asked about, the regulations, entities and constraints they named, '
      . 'and the facts the assistant stated together with the page each fact came from. Drop greetings and filler. '
      . sprintf('Write plain prose of at most %d words. Reply with the summary only.', $this->summaryWords);

    $lines = [];
    $lines[] = 'Earlier summary: ' . ($state->summary !== '' ? $state->summary : '(none)');
    $lines[] = '';
    $lines[] = 'Messages leaving the window:';
    foreach ($evicted as $message) {
      $lines[] = ($message['role'] === 'user' ? 'Visitor: ' : 'Assistant: ') . $message['content'];
    }
    return ['system' => $system, 'user' => implode("\n", $lines)];
  }

  /**
   * Stores a fresh summary and marks the pending messages as folded.
   */
  public function applyFold(ThreadState $state, string $summary): void {
    $state->summary = self::limitWords(trim($summary), $this->summaryWords);
    $state->folded = max($state->folded, count($state->messages) - $this->windowMessages);
  }

  /**
   * Folds without a model call, used when the fold call fails.
   *
   * The visitor's questions are the part a later turn most needs, so they are
   * kept; the assistant's answers are dropped.
   */
  public function applyFallbackFold(ThreadState $state, array $evicted): void {
    $questions = [];
    foreach ($evicted as $message) {
      if ($message['role'] === 'user') {
        $questions[] = $message['content'];
      }
    }
    $summary = trim($state->summary . ' Earlier the visitor asked: ' . implode(' / ', $questions));
    $this->applyFold($state, $summary);
  }

  /**
   * Returns the last cited pages as numbered prior sources.
   *
   * @return array<int, array{n: int, title: string, url: string}>
   *   Most recent last, numbered from 1.
   */
  public function priorSources(ThreadState $state): array {
    $sources = array_slice(array_values($state->citedSources), -self::PRIOR_SOURCES_LIMIT);
    $out = [];
    foreach ($sources as $i => $source) {
      $out[] = ['n' => $i + 1, 'title' => $source['title'], 'url' => $source['url']];
    }
    return $out;
  }

  /**
   * Assembles the message list for the answer call.
   *
   * @param string $currentContent
   *   The current user turn, search context included.
   *
   * @return array{messages: array<int, array{role: string, content: string}>, dropped: int}
   *   The messages for conversation(), and how many the backstop removed.
   */
  public function assemble(ThreadState $state, string $currentContent): array {
    $window = array_slice($state->messages, -$this->windowMessages);
    $messages = [];
    foreach ($window as $message) {
      $messages[] = ['role' => $message['role'], 'content' => $message['content']];
    }
    // A conversation sent to the model must start with a user turn. After an
    // odd number of evictions the window can open on an assistant message.
    while ($messages !== [] && $messages[0]['role'] !== 'user') {
      array_shift($messages);
    }
    $messages[] = ['role' => 'user', 'content' => $currentContent];

    $before = count($messages);
    $messages = self::truncate($messages, $this->historyChars);
    return ['messages' => $messages, 'dropped' => $before - count($messages)];
  }

  /**
   * The truncate_conversation rule, with the newest messages protected.
   *
   * Mirrors scolta-core src/conversation.rs: while the total character count
   * exceeds $maxLength, drop the oldest $removalUnit messages after the first
   * $preserveFirstN. The last $protectLast messages are never removed.
   *
   * @param array<int, array{role: string, content: string}> $messages
   *   The messages, oldest first.
   *
   * @return array<int, array{role: string, content: string}>
   *   The trimmed list.
   */
  public static function truncate(array $messages, int $maxLength, int $preserveFirstN = 2, int $removalUnit = 2, int $protectLast = 1): array {
    $messages = array_values($messages);
    while (TRUE) {
      $total = 0;
      foreach ($messages as $message) {
        $total += mb_strlen($message['content']);
      }
      if ($total <= $maxLength) {
        break;
      }
      $removable = count($messages) - $preserveFirstN - $protectLast;
      if ($removable <= 0) {
        break;
      }
      array_splice($messages, $preserveFirstN, min($removalUnit, $removable));
    }
    return $messages;
  }

  /**
   * Cuts text to at most $words words.
   */
  public static function limitWords(string $text, int $words): string {
    $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) <= $words) {
      return implode(' ', $parts);
    }
    return implode(' ', array_slice($parts, 0, $words));
  }

}
