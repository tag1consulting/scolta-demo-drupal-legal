<?php

namespace Drupal\complianceiq_scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles ComplianceIQ search with Scolta expansion.
 */
class SearchController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Main search results page.
   */
  public function search(Request $request): array {
    // Prevent page caching for search results.
    \Drupal::service('page_cache_kill_switch')->trigger();
    $query = trim($request->query->get('q', ''));
    $filters = [
      'regulation' => $request->query->get('regulation', ''),
      'content_type' => $request->query->get('content_type', ''),
      'audience' => $request->query->get('audience', ''),
      'severity' => $request->query->get('severity', ''),
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
   * Return Scolta-style expansion terms for a query.
   */
  private function getExpansionTerms(string $query): array {
    $q = strtolower($query);

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
  private function executeSearch(string $query, array $expansion_terms, array $filters = [], int $limit = 20): array {
    $search_terms = array_merge([$query], $expansion_terms);
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
      $db_query->condition('n.type', $filters['content_type']);
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
        'url' => '/node/' . $row->nid,
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
