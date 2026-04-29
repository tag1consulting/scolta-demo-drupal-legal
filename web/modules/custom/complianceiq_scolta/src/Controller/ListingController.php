<?php

namespace Drupal\complianceiq_scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ListingController extends ControllerBase {

  // Maps URL slugs → taxonomy term names.
  private const FRAMEWORK_MAP = [
    'gdpr'    => 'GDPR',
    'hipaa'   => 'HIPAA',
    'ccpa'    => 'CCPA/CPRA',
    'sox'     => 'SOX',
    'pci-dss' => 'PCI-DSS',
    'ferpa'   => 'FERPA',
    'ada-508' => 'ADA/Section 508',
    'fedramp' => 'FedRAMP',
  ];

  private const AUDIENCE_MAP = [
    'legal'     => 'Legal/Compliance',
    'it'        => 'IT/Security',
    'executive' => 'Executive/Board',
  ];

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

  // -------------------------------------------------------------------------
  // /regulations/{framework}
  // -------------------------------------------------------------------------
  public function regulation(string $framework): array {
    \Drupal::service('page_cache_kill_switch')->trigger();

    $label = self::FRAMEWORK_MAP[$framework] ?? null;
    if (!$label) {
      return $this->notFound("Unknown regulation: $framework");
    }

    $sections   = $this->fetchByRegulation('regulation_section', $label, 'field_regulation');
    $guidance   = $this->fetchByRegulation('guidance_article',   $label, 'field_regulations_covered');
    $cases      = $this->fetchByRegulation('enforcement_case',   $label, 'field_regulation_violated');
    $checklists = $this->fetchByRegulation('checklist',          $label, 'field_regulation');

    return [
      '#theme' => 'complianceiq_regulation',
      '#framework' => $label,
      '#sections'   => $sections,
      '#guidance'   => $guidance,
      '#cases'      => $cases,
      '#checklists' => $checklists,
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  // -------------------------------------------------------------------------
  // /guidance  and  /guidance/{audience}
  // -------------------------------------------------------------------------
  public function guidance(string $audience = ''): array {
    \Drupal::service('page_cache_kill_switch')->trigger();

    $audience_label = self::AUDIENCE_MAP[$audience] ?? '';
    $rows = $this->fetchGuidance($audience_label);

    return [
      '#theme' => 'complianceiq_listing',
      '#page_title' => $audience_label ? "Guidance for $audience_label" : 'All Guidance',
      '#items' => $rows,
      '#empty_message' => 'No guidance articles found.',
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  // -------------------------------------------------------------------------
  // /enforcement  and  /enforcement/{filter}
  // -------------------------------------------------------------------------
  public function enforcement(string $filter = ''): array {
    \Drupal::service('page_cache_kill_switch')->trigger();

    $order = match($filter) {
      'largest-penalties' => ['field_penalty_numeric', 'DESC'],
      'recent'            => ['n.changed', 'DESC'],
      default             => ['n.changed', 'DESC'],
    };

    $title = match($filter) {
      'largest-penalties' => 'Largest Penalties',
      'recent'            => 'Recent Enforcement Actions',
      default             => 'All Enforcement Actions',
    };

    $rows = $this->fetchEnforcement($order);

    return [
      '#theme' => 'complianceiq_listing',
      '#page_title' => $title,
      '#items' => $rows,
      '#empty_message' => 'No enforcement cases found.',
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  // -------------------------------------------------------------------------
  // /about  /sources  /checklists  /comparisons  /glossary
  // -------------------------------------------------------------------------
  public function about(): array {
    return ['#theme' => 'complianceiq_about', '#cache' => ['max-age' => 86400]];
  }

  public function sources(): array {
    return ['#theme' => 'complianceiq_sources', '#cache' => ['max-age' => 86400]];
  }

  public function checklists(): array {
    \Drupal::service('page_cache_kill_switch')->trigger();
    return [
      '#theme' => 'complianceiq_listing',
      '#page_title' => 'Checklists',
      '#items' => $this->fetchByType('checklist'),
      '#empty_message' => 'No checklists found.',
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  public function comparisons(): array {
    \Drupal::service('page_cache_kill_switch')->trigger();
    return [
      '#theme' => 'complianceiq_listing',
      '#page_title' => 'Cross-Regulation Comparisons',
      '#items' => $this->fetchByType('comparison'),
      '#empty_message' => 'No comparisons found.',
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  public function glossary(): array {
    \Drupal::service('page_cache_kill_switch')->trigger();
    return [
      '#theme' => 'complianceiq_listing',
      '#page_title' => 'Glossary',
      '#items' => $this->fetchByType('glossary_term'),
      '#empty_message' => 'No glossary terms found.',
      '#cache' => ['max-age' => 3600, 'tags' => ['node_list']],
    ];
  }

  // -------------------------------------------------------------------------
  // Queries
  // -------------------------------------------------------------------------
  private function resolveUrls(array $rows): array {
    foreach ($rows as $row) {
      $row->url = $this->aliasManager->getAliasByPath('/node/' . $row->nid);
    }
    return $rows;
  }

  private function fetchByType(string $type): array {
    $q = $this->database->select('node_field_data', 'n');
    $q->join('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
    $q->fields('n', ['nid', 'title', 'type']);
    $q->fields('b', ['body_value']);
    $q->condition('n.status', 1);
    $q->condition('n.type', $type);
    $q->orderBy('n.title', 'ASC');
    $q->distinct();
    return $this->resolveUrls($q->execute()->fetchAll());
  }

  private function fetchByRegulation(string $type, string $framework, string $field): array {
    $table = 'node__' . $field;
    try {
      $q = $this->database->select('node_field_data', 'n');
      $q->join($table, 'f', "f.entity_id = n.nid");
      $q->join('taxonomy_term_field_data', 't', "t.tid = f.{$field}_target_id");
      $q->join('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
      $q->fields('n', ['nid', 'title', 'type']);
      $q->fields('b', ['body_value']);
      $q->condition('n.status', 1);
      $q->condition('n.type', $type);
      $q->condition('t.name', $framework);
      $q->orderBy('n.title', 'ASC');
      $q->distinct();
      return $this->resolveUrls($q->execute()->fetchAll());
    }
    catch (\Exception) {
      return [];
    }
  }

  private function fetchGuidance(string $audience_label): array {
    $q = $this->database->select('node_field_data', 'n');
    $q->join('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
    $q->fields('n', ['nid', 'title', 'type']);
    $q->fields('b', ['body_value']);
    $q->condition('n.status', 1);
    $q->condition('n.type', 'guidance_article');

    if ($audience_label) {
      $q->join('node__field_audience', 'a', 'a.entity_id = n.nid');
      $q->join('taxonomy_term_field_data', 't', 't.tid = a.field_audience_target_id');
      $q->condition('t.name', $audience_label);
    }

    $q->orderBy('n.title', 'ASC');
    $q->distinct();
    return $this->resolveUrls($q->execute()->fetchAll());
  }

  private function fetchEnforcement(array $order): array {
    $q = $this->database->select('node_field_data', 'n');
    $q->join('node__body', 'b', 'b.entity_id = n.nid AND b.langcode = n.langcode');
    $q->fields('n', ['nid', 'title', 'type']);
    $q->fields('b', ['body_value']);
    $q->condition('n.status', 1);
    $q->condition('n.type', 'enforcement_case');

    if ($order[0] === 'field_penalty_numeric') {
      $q->leftJoin('node__field_penalty_numeric', 'p', 'p.entity_id = n.nid');
      $q->addField('p', 'field_penalty_numeric_value', 'penalty');
      $q->orderBy('p.field_penalty_numeric_value', 'DESC');
    }
    else {
      $q->orderBy($order[0], $order[1]);
    }

    $q->distinct();
    return $this->resolveUrls($q->execute()->fetchAll());
  }

  private function notFound(string $msg): array {
    return [
      '#markup' => '<p>' . htmlspecialchars($msg) . '</p>',
    ];
  }

}
