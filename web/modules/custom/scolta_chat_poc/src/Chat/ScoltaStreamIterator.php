<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

use Drupal\ai\OperationType\Chat\StreamedChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;

/**
 * Hands the streamed answer pieces to Drupal AI's DeepChat API.
 *
 * DeepChatApi::createStreamedResponse() iterates this, re-renders the growing
 * text on every piece (CommonMark, then its XSS filter), then calls the
 * processor's onStreamComplete() and appends getPostResponseMarkup().
 *
 * Pieces are yielded as StreamedChatMessage objects directly rather than
 * through createStreamedChatMessage(), which runs Drupal AI's hostname
 * filter. In Drupal AI 1.5 that filter (plain text mode, which streaming
 * forces) trims trailing punctuation off each allowed URL and puts the URL
 * back without it, so "[[1]](https://site/page)." loses its closing
 * parenthesis and every citation link breaks. The same rule is applied here
 * instead: a Markdown link survives only when it points at this site, and
 * any other link keeps its text and loses the link. A piece that ends inside
 * a link is held back until the link is complete, so the filter always sees
 * whole links.
 */
final class ScoltaStreamIterator extends StreamedChatMessageIterator {

  private const LINK = '/\[((?:[^\[\]]|\[[^\[\]]*\])+)\]\(([^()\s]*)\)/u';

  private const MAX_HOLD = 600;

  private string $siteHost = '';

  /**
   * Sets the host whose links may stay linked.
   */
  public function setSiteHost(string $host): static {
    $this->siteHost = $host;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
    $held = '';
    foreach ($this->iterator as $piece) {
      $held .= (string) $piece;
      [$ready, $held] = $this->split($held);
      if ($ready !== '') {
        yield $this->message($this->filterLinks($ready));
      }
    }
    if ($held !== '') {
      yield $this->message($this->filterLinks($held));
    }
  }

  /**
   * Splits text into what can go out now and what must wait for a link end.
   *
   * @return array{0: string, 1: string}
   */
  private function split(string $text): array {
    $open = mb_strrpos($text, '[');
    if ($open === FALSE) {
      return [$text, ''];
    }
    $tail = mb_substr($text, $open);
    // Find where the outermost link starting near the end begins: a "[[n]"
    // citation opens one bracket earlier.
    if ($open > 0 && mb_substr($text, $open - 1, 1) === '[') {
      $open--;
      $tail = mb_substr($text, $open);
    }
    $complete = preg_match('/^\[(?:[^\[\]]|\[[^\[\]]*\])+\](?:\([^()\s]*\)|[^(])/u', $tail) === 1
      || (mb_strlen($tail) > self::MAX_HOLD);
    if ($complete) {
      return [$text, ''];
    }
    return [mb_substr($text, 0, $open), $tail];
  }

  private function filterLinks(string $text): string {
    return preg_replace_callback(self::LINK, function (array $m): string {
      return TurnPayload::isSiteUrl($m[2], $this->siteHost) ? $m[0] : $m[1];
    }, $text) ?? $text;
  }

  private function message(string $text): StreamedChatMessage {
    $message = new StreamedChatMessage('assistant', $text, []);
    $this->messages[] = $message;
    return $message;
  }

}
