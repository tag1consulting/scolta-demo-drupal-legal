<?php

namespace Drupal\complianceiq_import;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for creating compliance content nodes.
 */
class ComplianceImporter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Ensure a taxonomy term exists, return its ID.
   */
  public function ensureTerm(string $vocab, string $name): int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => $vocab, 'name' => $name]);
    if ($terms) {
      return reset($terms)->id();
    }
    $term = $storage->create(['vid' => $vocab, 'name' => $name]);
    $term->save();
    return $term->id();
  }

  /**
   * Create or update a Regulation Section node.
   */
  public function createRegulationSection(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    // Check for existing node by section number + regulation.
    if (!empty($data['section_number'])) {
      $existing = $storage->loadByProperties([
        'type' => 'regulation_section',
        'field_section_number' => $data['section_number'],
      ]);
      if ($existing) {
        return reset($existing)->id();
      }
    }

    $fields = [
      'type' => 'regulation_section',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'full_html'],
    ];

    if (!empty($data['plain_summary'])) {
      $fields['field_plain_summary'] = ['value' => $data['plain_summary'], 'format' => 'basic_html'];
    }
    if (!empty($data['section_number'])) {
      $fields['field_section_number'] = $data['section_number'];
    }
    if (!empty($data['chapter'])) {
      $fields['field_chapter'] = $data['chapter'];
    }
    if (!empty($data['enforcement_body'])) {
      $fields['field_enforcement_body'] = $data['enforcement_body'];
    }
    if (!empty($data['effective_date'])) {
      $fields['field_effective_date'] = $data['effective_date'];
    }
    if (!empty($data['severity'])) {
      $fields['field_severity'] = $data['severity'];
    }
    if (!empty($data['regulation'])) {
      $tid = $this->ensureTerm('regulation_framework', $data['regulation']);
      $fields['field_regulation'] = $tid;
    }
    if (!empty($data['jurisdiction'])) {
      $tid = $this->ensureTerm('jurisdiction', $data['jurisdiction']);
      $fields['field_jurisdiction'] = $tid;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Create an Enforcement Case node.
   */
  public function createEnforcementCase(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    $fields = [
      'type' => 'enforcement_case',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'full_html'],
    ];

    if (!empty($data['key_facts'])) {
      $fields['field_key_facts'] = ['value' => $data['key_facts'], 'format' => 'basic_html'];
    }
    if (!empty($data['lessons'])) {
      $fields['field_lessons'] = ['value' => $data['lessons'], 'format' => 'basic_html'];
    }
    if (!empty($data['penalty_amount'])) {
      $fields['field_penalty_amount'] = $data['penalty_amount'];
    }
    if (!empty($data['penalty_numeric'])) {
      $fields['field_penalty_numeric'] = $data['penalty_numeric'];
    }
    if (!empty($data['date'])) {
      $fields['field_date'] = $data['date'];
    }
    if (!empty($data['source_url'])) {
      $fields['field_source_url'] = ['uri' => $data['source_url'], 'title' => 'Enforcement Record'];
    }
    if (!empty($data['enforcement_body'])) {
      $tid = $this->ensureTerm('enforcement_body', $data['enforcement_body']);
      $fields['field_enforcement_body'] = $tid;
    }
    if (!empty($data['industry'])) {
      $tid = $this->ensureTerm('industry', $data['industry']);
      $fields['field_industry'] = $tid;
    }
    if (!empty($data['regulations'])) {
      $tids = [];
      foreach ($data['regulations'] as $reg) {
        $tids[] = $this->ensureTerm('regulation_framework', $reg);
      }
      $fields['field_regulation_violated'] = $tids;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Create a Guidance Article node.
   */
  public function createGuidanceArticle(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    $fields = [
      'type' => 'guidance_article',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'full_html'],
    ];

    if (!empty($data['key_takeaways'])) {
      $fields['field_key_takeaways'] = ['value' => $data['key_takeaways'], 'format' => 'basic_html'];
    }
    if (!empty($data['difficulty'])) {
      $fields['field_difficulty'] = $data['difficulty'];
    }
    if (!empty($data['last_reviewed'])) {
      $fields['field_last_reviewed'] = $data['last_reviewed'];
    }
    if (!empty($data['audience'])) {
      $tid = $this->ensureTerm('audience', $data['audience']);
      $fields['field_audience'] = $tid;
    }
    if (!empty($data['regulations'])) {
      $tids = [];
      foreach ($data['regulations'] as $reg) {
        $tids[] = $this->ensureTerm('regulation_framework', $reg);
      }
      $fields['field_regulations_covered'] = $tids;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Create a Checklist node.
   */
  public function createChecklist(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    $fields = [
      'type' => 'checklist',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'basic_html'],
      'field_checklist_items' => ['value' => $data['checklist_items'] ?? '', 'format' => 'full_html'],
    ];

    if (!empty($data['regulation'])) {
      $tid = $this->ensureTerm('regulation_framework', $data['regulation']);
      $fields['field_regulation'] = $tid;
    }
    if (!empty($data['audience'])) {
      $tid = $this->ensureTerm('audience', $data['audience']);
      $fields['field_audience'] = $tid;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Create a Comparison node.
   */
  public function createComparison(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    $fields = [
      'type' => 'comparison',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'full_html'],
    ];

    if (!empty($data['comparison_table'])) {
      $fields['field_comparison_table'] = ['value' => $data['comparison_table'], 'format' => 'full_html'];
    }
    if (!empty($data['key_differences'])) {
      $fields['field_key_differences'] = ['value' => $data['key_differences'], 'format' => 'basic_html'];
    }
    if (!empty($data['regulations'])) {
      $tids = [];
      foreach ($data['regulations'] as $reg) {
        $tids[] = $this->ensureTerm('regulation_framework', $reg);
      }
      $fields['field_regulations_compared'] = $tids;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Create a Glossary Term node.
   */
  public function createGlossaryTerm(array $data): int {
    $storage = $this->entityTypeManager->getStorage('node');

    $fields = [
      'type' => 'glossary_term',
      'title' => $data['title'],
      'status' => 1,
      'body' => ['value' => $data['body'] ?? '', 'format' => 'basic_html'],
    ];

    if (!empty($data['regulation_definitions'])) {
      $fields['field_regulation_definitions'] = ['value' => $data['regulation_definitions'], 'format' => 'basic_html'];
    }
    if (!empty($data['regulations'])) {
      $tids = [];
      foreach ($data['regulations'] as $reg) {
        $tids[] = $this->ensureTerm('regulation_framework', $reg);
      }
      $fields['field_regulations'] = $tids;
    }

    $node = $storage->create($fields);
    $node->save();
    return $node->id();
  }

  /**
   * Build cross-references between all content types by regulation framework.
   */
  public function buildCrossReferences(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load all regulation_framework terms.
    $frameworks = $term_storage->loadByProperties(['vid' => 'regulation_framework']);

    foreach ($frameworks as $framework) {
      $tid = $framework->id();

      // Get regulation sections for this framework.
      $sections = $storage->loadByProperties(['type' => 'regulation_section', 'field_regulation' => $tid]);
      $section_ids = array_keys($sections);

      // Get guidance articles covering this framework.
      $guidance = $storage->loadByProperties(['type' => 'guidance_article', 'field_regulations_covered' => $tid]);

      // Get enforcement cases for this framework.
      $cases = $storage->loadByProperties(['type' => 'enforcement_case', 'field_regulation_violated' => $tid]);
      $case_ids = array_keys($cases);

      // Link guidance articles to sections in the same framework.
      foreach ($guidance as $article) {
        if (empty($section_ids)) continue;
        $sample = array_slice($section_ids, 0, min(3, count($section_ids)));
        $article->set('field_related_sections', $sample);
        if (!empty($case_ids)) {
          $article->set('field_related_cases', array_slice($case_ids, 0, min(2, count($case_ids))));
        }
        $article->save();
      }

      // Link sections to related cases.
      foreach ($sections as $section) {
        if (!empty($case_ids)) {
          $section->set('field_related_cases', array_slice($case_ids, 0, min(2, count($case_ids))));
          $section->save();
        }
      }
    }
  }

  protected function logger(): \Psr\Log\LoggerInterface {
    return $this->loggerFactory->get('complianceiq_import');
  }

}
