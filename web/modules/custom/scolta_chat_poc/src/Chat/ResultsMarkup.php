<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Unicode;

/**
 * Renders a turn's search results under the answer, and finds citations.
 *
 * The results sit in a closed <details> element, so a reply shows the answer
 * and a one line "Sources" toggle rather than five titles and excerpts.
 *
 * DeepChatApi appends getPostResponseMarkup() after its Xss filter,
 * verbatim, so this markup is trusted output: every value in it comes from
 * the browser and is escaped here. Styles are inline because the chat renders
 * inside the deep-chat element's shadow root, where the theme's CSS does not
 * reach.
 */
final class ResultsMarkup {

  private const EXCERPT_CHARS = 160;

  /**
   * Returns the result numbers the answer cited.
   *
   * A result counts as cited when the answer links its URL, or carries its
   * [n] marker.
   *
   * @param array<int, array{n: int, url: string}> $results
   *   The turn's results.
   *
   * @return int[]
   *   The cited result numbers, ascending.
   */
  public static function citedNumbers(string $answer, array $results): array {
    $cited = [];
    foreach ($results as $result) {
      $url = $result['url'];
      $path = parse_url($url, PHP_URL_PATH) ?: $url;
      $linked = str_contains($answer, '](' . $url . ')')
        || ($path !== '' && $path !== '/' && preg_match('#\]\((?:https?://[^/)\s]+)?' . preg_quote($path, '#') . '\)#', $answer));
      $marked = str_contains($answer, '[' . $result['n'] . ']');
      if ($linked || $marked) {
        $cited[] = $result['n'];
      }
    }
    sort($cited);
    return $cited;
  }

  /**
   * Renders the results block.
   *
   * @param string $query
   *   The rewritten search query.
   * @param array<int, array{n: int, title: string, url: string, excerpt: string}> $results
   *   The turn's results.
   * @param int[] $cited
   *   The result numbers the answer cited.
   * @param string $siteName
   *   The site name.
   */
  public static function render(string $query, array $results, array $cited, string $siteName): string {
    $e = static fn (string $value): string => Html::escape($value);
    $site = $siteName !== '' ? $siteName : 'this site';

    $count = count($results);
    $citedCount = count(array_intersect(array_column($results, 'n'), $cited));
    if ($count === 0) {
      $label = 'Sources: no pages matched';
    }
    elseif ($citedCount > 0) {
      $label = 'Sources: ' . $citedCount . ' cited, ' . $count . ' found';
    }
    else {
      $label = 'Sources: ' . $count . ' found, none cited';
    }

    $html = '<details class="scolta-chat-results" style="margin-top:10px;padding-top:6px;border-top:1px solid #d0d7de;font-size:0.92em">';
    $html .= '<summary style="cursor:pointer;color:#57606a">' . $e($label) . '</summary>';
    $html .= '<div style="margin-top:6px">';
    $html .= '<p style="margin:0 0 6px"><strong>' . $e('Search results for: ' . $query) . '</strong></p>';

    if ($results === []) {
      $html .= '<p style="margin:0 0 6px">' . $e('No pages on ' . $site . ' matched this search.') . '</p>';
    }
    else {
      $html .= '<ol style="margin:0 0 6px;padding-left:1.4em">';
      foreach ($results as $result) {
        $excerpt = Unicode::truncate(trim($result['excerpt']), self::EXCERPT_CHARS, TRUE, TRUE);
        $html .= '<li value="' . (int) $result['n'] . '" style="margin:0 0 6px">';
        $html .= '<a href="' . $e(UrlHelper::stripDangerousProtocols($result['url'])) . '" target="_blank" rel="noopener">' . $e($result['title']) . '</a>';
        if (in_array($result['n'], $cited, TRUE)) {
          $html .= ' <em style="color:#1a7f37">(cited)</em>';
        }
        if ($excerpt !== '') {
          $html .= '<br><span style="color:#57606a">' . $e($excerpt) . '</span>';
        }
        $html .= '</li>';
      }
      $html .= '</ol>';
    }

    $html .= '<p style="margin:0;color:#57606a">' . $e('From pages on ' . $site) . '</p>';
    $html .= '</div></details>';
    return $html;
  }

}
