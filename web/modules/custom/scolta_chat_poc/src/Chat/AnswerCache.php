<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

/**
 * The cache key for a first turn answer.
 *
 * Only an opening turn is cached: its answer depends on nothing but the
 * question, the search results sent with it and the prompt, the same inputs
 * Scolta's summary cache keys on. Later turns depend on the conversation and
 * are never cached. The model is part of the key, so switching models does
 * not serve old answers.
 *
 * Framework free: shared by the Drupal module and the WordPress plugin, which
 * each keep the entries in their own cache.
 */
final class AnswerCache {

  /**
   * @param array<int, array{role: string, content: string}> $messages
   */
  public static function key(string $model, string $system, array $messages, int $maxTokens): string {
    return hash('sha256', json_encode([$model, $system, $messages, $maxTokens]) ?: '');
  }

}
