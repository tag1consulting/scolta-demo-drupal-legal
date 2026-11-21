<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

/**
 * The browser's search results for one turn, sanitized.
 *
 * The browser runs the search (Scolta search exists only there) and posts the
 * results alongside the chat message as `scolta`. Everything in it is client
 * supplied, so it is bounded here before it reaches a prompt or the page:
 * at most five results, short titles and excerpts, a context string cut to
 * the configured budget, and only links that stay on this site.
 */
final class TurnPayload {

  public const MAX_RESULTS = 5;

  private const MAX_QUERY = 300;

  private const MAX_TITLE = 200;

  private const MAX_EXCERPT = 400;

  private const MAX_SEED_SUMMARY = 3000;

  /**
   * @param string $query
   *   The rewritten standalone search query.
   * @param string[] $expandedTerms
   *   The AI expansion terms merged into the search.
   * @param array<int, array{n: int, title: string, url: string, excerpt: string}> $results
   *   The shown results, numbered as the context numbers them.
   * @param string $context
   *   The search context for the model.
   * @param bool $needsSearch
   *   FALSE when the rewrite judged the turn small talk.
   * @param bool $searchFailed
   *   TRUE when the browser reported that the search itself failed.
   * @param array{query: string, summary: string, results: array}|null $seed
   *   The search follow up hand off, if any.
   */
  public function __construct(
    public readonly string $query,
    public readonly array $expandedTerms,
    public readonly array $results,
    public readonly string $context,
    public readonly bool $needsSearch,
    public readonly bool $searchFailed,
    public readonly ?array $seed,
  ) {}

  /**
   * Builds a payload from the decoded request body's `scolta` member.
   *
   * @param mixed $raw
   *   The decoded `scolta` value, anything at all.
   * @param string $fallbackQuery
   *   Used when the payload carries no query (the visitor's own words).
   * @param int $contextChars
   *   The configured context budget.
   * @param string $siteHost
   *   The host the links must stay on.
   */
  public static function fromRequest(mixed $raw, string $fallbackQuery, int $contextChars, string $siteHost): self {
    $raw = is_array($raw) ? $raw : [];
    $query = self::text($raw['query'] ?? '', self::MAX_QUERY);
    if ($query === '') {
      $query = self::text($fallbackQuery, self::MAX_QUERY);
    }

    $terms = [];
    foreach (array_slice(is_array($raw['expanded_terms'] ?? NULL) ? $raw['expanded_terms'] : [], 0, 12) as $term) {
      $term = self::text($term, 100);
      if ($term !== '') {
        $terms[] = $term;
      }
    }

    $results = self::results($raw['results'] ?? [], $siteHost);

    // Half again the budget allows for the [n] title and URL lines the
    // browser adds around each extracted excerpt.
    $context = is_string($raw['context'] ?? NULL) ? $raw['context'] : '';
    $context = mb_substr(trim($context), 0, (int) floor($contextChars * 1.5));
    if ($results === []) {
      $context = '';
    }

    $seed = NULL;
    if (is_array($raw['seed'] ?? NULL)) {
      $seedQuery = self::text($raw['seed']['query'] ?? '', self::MAX_QUERY);
      $seedSummary = is_string($raw['seed']['summary'] ?? NULL) ? trim(mb_substr($raw['seed']['summary'], 0, self::MAX_SEED_SUMMARY)) : '';
      if ($seedQuery !== '' && $seedSummary !== '') {
        $seed = [
          'query' => $seedQuery,
          'summary' => $seedSummary,
          'results' => self::results($raw['seed']['results'] ?? [], $siteHost),
        ];
      }
    }

    return new self(
      $query,
      $terms,
      $results,
      $context,
      ($raw['needs_search'] ?? TRUE) !== FALSE,
      ($raw['search_failed'] ?? FALSE) === TRUE,
      $seed,
    );
  }

  /**
   * Returns TRUE when a URL may be linked from this site's chat.
   *
   * Root relative paths and absolute http(s) URLs on the site's own host
   * pass; everything else (other hosts, javascript:, data:, protocol
   * relative) is refused.
   */
  public static function isSiteUrl(string $url, string $siteHost): bool {
    if ($url === '' || preg_match('/[\s<>"]/', $url)) {
      return FALSE;
    }
    if (str_starts_with($url, '/')) {
      return !str_starts_with($url, '//');
    }
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], TRUE)) {
      return FALSE;
    }
    return strcasecmp($parts['host'] ?? '', $siteHost) === 0;
  }

  private static function results(mixed $raw, string $siteHost): array {
    // Numbered by position: the browser numbers the context the same way.
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $item) {
      if (!is_array($item) || count($out) >= self::MAX_RESULTS) {
        continue;
      }
      $url = is_string($item['url'] ?? NULL) ? trim($item['url']) : '';
      if (!self::isSiteUrl($url, $siteHost)) {
        continue;
      }
      $title = self::text($item['title'] ?? '', self::MAX_TITLE);
      $out[] = [
        'n' => count($out) + 1,
        'title' => $title !== '' ? $title : $url,
        'url' => $url,
        'excerpt' => self::text($item['excerpt'] ?? '', self::MAX_EXCERPT),
      ];
    }
    return $out;
  }

  private static function text(mixed $value, int $max): string {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
      return '';
    }
    $value = preg_replace('/\s+/u', ' ', (string) $value) ?? '';
    return trim(mb_substr(trim($value), 0, $max));
  }

}
