<?php

namespace Drupal\complianceiq_scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles ComplianceIQ search with Scolta expansion.
 */
class SearchController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected AliasManagerInterface $aliasManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('path_alias.manager'),
    );
  }

  /**
   * Main search results page.
   */
  public function search(Request $request): array {
    // Prevent page caching for search results.
    \Drupal::service('page_cache_kill_switch')->trigger();
    $query = trim($request->query->get('q', ''));
    $all = $request->query->all();
    $filters = [
      'regulation'   => array_values(array_filter((array) ($all['regulation'] ?? []))),
      'content_type' => array_values(array_filter((array) ($all['content_type'] ?? []))),
    ];

    $results = [];
    $expansion_terms = [];
    $total = 0;

    if ($query) {
      $expansion_terms = $this->getExpansionTerms($query);
      [$results, $total] = $this->executeSearch($query, $expansion_terms, $filters);
    }

    return [
      '#theme' => 'complianceiq_search',
      '#query' => $query,
      '#results' => $results,
      '#total' => $total,
      '#expansion_terms' => $expansion_terms,
      '#ai_summary' => '',
      '#filters' => $filters,
      '#attached' => ['library' => ['complianceiq/search']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url.query_args'],
      ],
    ];
  }

  /**
   * JSON API endpoint for autocomplete/AJAX search.
   */
  public function searchApi(Request $request): JsonResponse {
    $query = trim($request->query->get('q', ''));
    if (!$query) {
      return new JsonResponse(['results' => [], 'expansion_terms' => []]);
    }

    $expansion_terms = $this->getExpansionTerms($query);
    [$results] = $this->executeSearch($query, $expansion_terms, [], 10);

    return new JsonResponse([
      'results' => array_map(fn($r) => [
        'nid' => $r['nid'],
        'title' => $r['title'],
        'url' => $r['url'],
        'type' => $r['type'],
        'regulation' => $r['regulation'],
        'snippet' => substr(strip_tags($r['body']), 0, 150) . '...',
      ], $results),
      'expansion_terms' => $expansion_terms,
    ]);
  }

  /**
   * Async endpoint: returns AI summary JSON for a given query.
   */
  public function searchSummaryApi(Request $request): JsonResponse {
    $query = trim($request->query->get('q', ''));
    if (!$query) {
      return new JsonResponse(['summary' => '']);
    }

    $expansion_terms = $this->getExpansionTerms($query);
    [$results] = $this->executeSearch($query, $expansion_terms, [], 5);
    $summary = $results ? $this->generateSummary($query, $results) : '';

    return new JsonResponse(['summary' => $summary]);
  }

  /**
   * Generate an AI summary of search results using the Claude API.
   */
  private function generateSummary(string $query, array $results): string {
    $api_key = getenv('SCOLTA_API_KEY') ?: getenv('ANTHROPIC_API_KEY');
    if (!$api_key) {
      return '';
    }

    $context = '';
    foreach (array_slice($results, 0, 5) as $r) {
      $snippet = substr(strip_tags($r['body']), 0, 300);
      $context .= "- [{$r['type']}] {$r['title']}: {$snippet}\n";
    }

    $prompt = <<<PROMPT
A user searched a compliance knowledge base for: "{$query}"

The top results are:
{$context}

Summarize what these regulations say about the user's question. Format your response as:
- One short plain-language intro sentence (no label, just the sentence).
- Then 3–4 bullet points, each starting with "- ", covering the key obligations, rules, or facts. Bold regulation names and key legal terms using **double asterisks**.

Do not add disclaimers. Do not use headings. Answer directly from the content.
PROMPT;

    try {
      $ch = curl_init('https://api.anthropic.com/v1/messages');
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
          'x-api-key: ' . $api_key,
          'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
          'model' => 'claude-haiku-4-5-20251001',
          'max_tokens' => 256,
          'messages' => [['role' => 'user', 'content' => $prompt]],
        ]),
        CURLOPT_TIMEOUT => 8,
      ]);
      $response = curl_exec($ch);
      curl_close($ch);

      $data = json_decode($response, true);
      $text = trim($data['content'][0]['text'] ?? '');
      $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

      // Split into intro sentence(s) and bullet lines.
      $lines = array_map('trim', explode("\n", $text));
      $intro_lines = [];
      $bullet_lines = [];
      foreach ($lines as $line) {
        if ($line === '') continue;
        if (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
          $bullet_lines[] = '<li>' . $m[1] . '</li>';
        }
        else {
          $intro_lines[] = $line;
        }
      }

      $html = '';
      if ($intro_lines) {
        $html .= '<p>' . implode(' ', $intro_lines) . '</p>';
      }
      if ($bullet_lines) {
        $html .= '<ul>' . implode('', $bullet_lines) . '</ul>';
      }
      return $html ?: $text;
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Return Scolta-style expansion terms for a query.
   */
  private function getExpansionTerms(string $query): array {
    // Common misspellings → canonical form before expansion lookup.
    static $corrections = [
      'hippa' => 'hipaa', 'hipaa' => 'hipaa',
      'gdpr'  => 'gdpr',  'gdrp'  => 'gdpr',
      'ccpa'  => 'ccpa',  'cpra'  => 'ccpa',
      'ferpa' => 'ferpa', 'ferpha' => 'ferpa',
      'fedramp' => 'fedramp',
      'sox'   => 'sox',
    ];

    $q = strtolower($query);
    foreach ($corrections as $typo => $canonical) {
      if ($typo !== $canonical) {
        $q = str_replace($typo, $canonical, $q);
      }
    }

    $expansions = [
      'hack' => ['breach', 'unauthorized access', 'security incident', 'intrusion', 'compromise'],
      'hacked' => ['data breach', 'unauthorized access', 'security incident', 'intrusion'],
      'breach' => ['unauthorized access', 'data compromise', 'security incident', 'notification obligation'],
      'email' => ['electronic communication', 'marketing', 'consent', 'opt-in', 'ePrivacy'],
      'europe' => ['EU', 'GDPR', 'data transfer', 'adequacy decision', 'EEA'],
      'european' => ['EU', 'GDPR', 'EEA', 'data subjects'],
      'delete' => ['erasure', 'right to be forgotten', 'data deletion', 'opt-out'],
      'deletion' => ['erasure', 'right to be forgotten', 'Art. 17', '§1798.105'],
      'accessible' => ['accessibility', 'WCAG', 'ADA', 'Section 508', 'disability'],
      'accessibility' => ['WCAG 2.1', 'ADA Title III', 'Section 508', 'screen reader'],
      'student' => ['FERPA', 'education records', 'school official exception', 'COPPA'],
      'patient' => ['PHI', 'HIPAA', 'protected health information', 'covered entity'],
      'credit card' => ['PAN', 'cardholder data', 'PCI-DSS', 'CDE', 'tokenization'],
      'payment' => ['PCI-DSS', 'cardholder data', 'PAN', 'merchant', 'card brands'],
      'audit' => ['risk assessment', 'internal controls', 'SOX 404', 'compliance review'],
      'cyber risk' => ['SOX 302', 'SEC disclosure', 'material weakness', 'NIST framework'],
      'cloud' => ['data transfer', 'third-party processor', 'FedRAMP', 'data processing agreement'],
      'vendor' => ['processor', 'business associate', 'third party', 'data processing agreement'],
      'fine' => ['penalty', 'enforcement', 'administrative fine', 'civil money penalty'],
      'consent' => ['lawful basis', 'opt-in', 'freely given', 'unambiguous', 'withdrawal'],
      'employee' => ['workforce member', 'minimum necessary', 'sanction policy', 'access controls'],
      'snooping' => ['unauthorized access', 'minimum necessary', 'workforce sanctions', '§164.530'],
      'board' => ['executive reporting', 'SOX 302', 'SEC disclosure', 'material weakness', 'cyber risk'],
    ];

    $terms = [];
    foreach ($expansions as $keyword => $expansion) {
      if (str_contains($q, $keyword)) {
        $terms = array_merge($terms, $expansion);
      }
    }

    return array_unique(array_slice($terms, 0, 8));
  }

  /**
   * Execute a full-text search across all content types.
   *
   * @return array [results, total]
   */
  private function tokenizeQuery(string $query): array {
    static $stop_words = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been',
      'what', 'how', 'why', 'when', 'where', 'who', 'which', 'do', 'does', 'did',
      'i', 'we', 'our', 'my', 'us', 'you', 'your', 'they', 'their', 'it', 'its',
      'can', 'should', 'would', 'could', 'will', 'may', 'might', 'must', 'have',
      'has', 'had', 'get', 'got', 'need', 'want', 'to', 'of', 'in', 'on', 'at',
      'for', 'with', 'about', 'from', 'by', 'as', 'this', 'that', 'these', 'those',
      'and', 'or', 'but', 'not', 'no', 'so', 'if', 'than', 'then', 'also', 'up'];

    static $corrections = [
      'hippa' => 'hipaa', 'gdrp' => 'gdpr', 'ferpha' => 'ferpa',
      'gdprs' => 'gdpr',  'ccpra' => 'ccpa',
    ];

    $words = preg_split('/\s+/', strtolower(trim($query)));
    $tokens = [];
    foreach ($words as $word) {
      $clean = preg_replace('/[^a-z0-9]/', '', $word);
      $clean = $corrections[$clean] ?? $clean;
      if (strlen($clean) > 2 && !in_array($clean, $stop_words)) {
        $tokens[] = $clean;
      }
    }
    return array_values(array_unique($tokens));
  }

  private function executeSearch(string $query, array $expansion_terms, array $filters = [], int $limit = 20): array {
    $tokens = $this->tokenizeQuery($query);
    // Use individual tokens for the raw query; fall back to the full phrase if
    // tokenization strips everything (e.g. a two-letter acronym query).
    $query_terms = $tokens ?: [$query];
    $search_terms = array_unique(array_merge($query_terms, $expansion_terms));
    $node_storage = $this->entityTypeManager()->getStorage('node');

    // Build OR condition across title + body for all search terms.
    $db_query = $this->database->select('node_field_data', 'n');
    $db_query->join('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
    $db_query->fields('n', ['nid', 'type', 'title']);
    $db_query->fields('b', ['body_value']);
    $db_query->condition('n.status', 1);

    $allowed_types = ['regulation_section', 'guidance_article', 'enforcement_case', 'checklist', 'comparison', 'glossary_term'];
    $db_query->condition('n.type', $allowed_types, 'IN');

    // Search condition: title OR body contains any search term.
    $or = $db_query->orConditionGroup();
    foreach ($search_terms as $term) {
      $or->condition('n.title', '%' . $this->database->escapeLike($term) . '%', 'LIKE');
      $or->condition('b.body_value', '%' . $this->database->escapeLike($term) . '%', 'LIKE');
    }
    $db_query->condition($or);

    // Apply content type filter.
    if (!empty($filters['content_type'])) {
      $db_query->condition('n.type', $filters['content_type'], 'IN');
    }

    // Apply regulation filter via subqueries across all three field tables.
    if (!empty($filters['regulation'])) {
      $tids = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.name', $filters['regulation'], 'IN')
        ->condition('t.vid', 'regulation_framework')
        ->execute()
        ->fetchCol();

      if ($tids) {
        $reg_or = $db_query->orConditionGroup();
        foreach ([
          'node__field_regulation'          => 'field_regulation_target_id',
          'node__field_regulations_covered' => 'field_regulations_covered_target_id',
          'node__field_regulation_violated' => 'field_regulation_violated_target_id',
        ] as $table => $column) {
          $sub = $this->database->select($table, 'rf')
            ->fields('rf', ['entity_id'])
            ->condition('rf.' . $column, $tids, 'IN');
          $reg_or->condition('n.nid', $sub, 'IN');
        }
        $db_query->condition($reg_or);
      }
    }

    // Relevance sort: title match on original tokens → body match → expansion only.
    if ($query_terms) {
      $title_whens = [];
      $body_whens = [];
      foreach ($query_terms as $term) {
        $escaped = $this->database->escapeLike($term);
        $title_whens[] = "n.title LIKE '%" . $escaped . "%'";
        $body_whens[]  = "b.body_value LIKE '%" . $escaped . "%'";
      }
      $title_expr = implode(' OR ', $title_whens);
      $body_expr  = implode(' OR ', $body_whens);
      $db_query->addExpression(
        "CASE WHEN ($title_expr) THEN 0 WHEN ($body_expr) THEN 1 ELSE 2 END",
        'relevance'
      );
      $db_query->orderBy('relevance', 'ASC');
    }
    $db_query->orderBy('n.changed', 'DESC');
    $db_query->distinct();

    $count_query = clone $db_query;
    $total = (int) $count_query->countQuery()->execute()->fetchField();

    $db_query->range(0, $limit);
    $rows = $db_query->execute()->fetchAll();

    $results = [];
    foreach ($rows as $row) {
      $results[] = [
        'nid' => $row->nid,
        'title' => $row->title,
        'type' => $row->type,
        'body' => $row->body_value,
        'url' => $this->aliasManager->getAliasByPath('/node/' . $row->nid),
        'regulation' => $this->getNodeRegulation($row->nid, $row->type),
      ];
    }

    return [$results, $total];
  }

  private function getNodeRegulation(int $nid, string $type): string {
    $field_map = [
      'regulation_section' => 'field_regulation',
      'guidance_article' => 'field_regulations_covered',
      'enforcement_case' => 'field_regulation_violated',
      'checklist' => 'field_regulation',
    ];

    $field = $field_map[$type] ?? null;
    if (!$field) return '';

    try {
      $query = $this->database->select('node__' . $field, 'f');
      $query->join('taxonomy_term_field_data', 't', 't.tid = f.' . $field . '_target_id');
      $query->fields('t', ['name']);
      $query->condition('f.entity_id', $nid);
      $query->range(0, 1);
      return (string) ($query->execute()->fetchField() ?: '');
    }
    catch (\Exception) {
      return '';
    }
  }

}
