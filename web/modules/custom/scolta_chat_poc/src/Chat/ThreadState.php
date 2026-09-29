<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

/**
 * The server side record of one chat thread.
 *
 * This is the only history the processor trusts. The DeepChat client sends
 * its own copy of the thread with every turn, and that copy is ignored apart
 * from the newest user message.
 */
final class ThreadState {

  /**
   * Every message of the thread, oldest first.
   *
   * User messages hold the visitor's words only, never the search excerpts
   * that travelled with them: excerpts are sent for the current turn alone.
   *
   * @var array<int, array{role: string, content: string, timestamp: int}>
   */
  public array $messages = [];

  /**
   * Running summary of the messages that slid out of the verbatim window.
   */
  public string $summary = '';

  /**
   * How many leading messages the summary already covers.
   */
  public int $folded = 0;

  /**
   * Pages the answers cited, keyed by URL, oldest first.
   *
   * @var array<string, array{title: string, url: string}>
   */
  public array $citedSources = [];

  /**
   * URLs whose excerpts were already sent to the model in this thread.
   *
   * @var array<string, bool>
   */
  public array $sentUrls = [];

  /**
   * Visitor turns answered so far (the seed exchange is not a turn).
   */
  public int $turns = 0;

  /**
   * Whether a search follow up seeded the opening exchange.
   */
  public bool $seeded = FALSE;

  /**
   * Builds a state from its stored array form.
   */
  public static function fromArray(?array $data): self {
    $state = new self();
    if (!$data) {
      return $state;
    }
    $state->messages = $data['messages'] ?? [];
    $state->summary = (string) ($data['summary'] ?? '');
    $state->folded = (int) ($data['folded'] ?? 0);
    $state->citedSources = $data['citedSources'] ?? [];
    $state->sentUrls = $data['sentUrls'] ?? [];
    $state->turns = (int) ($data['turns'] ?? 0);
    $state->seeded = (bool) ($data['seeded'] ?? FALSE);
    return $state;
  }

  /**
   * Returns the array form kept in the private tempstore.
   */
  public function toArray(): array {
    return [
      'messages' => $this->messages,
      'summary' => $this->summary,
      'folded' => $this->folded,
      'citedSources' => $this->citedSources,
      'sentUrls' => $this->sentUrls,
      'turns' => $this->turns,
      'seeded' => $this->seeded,
    ];
  }

  /**
   * Appends one message.
   */
  public function addMessage(string $role, string $content, ?int $timestamp = NULL): void {
    $this->messages[] = [
      'role' => $role,
      'content' => $content,
      'timestamp' => $timestamp ?? time(),
    ];
  }

}
