<?php

/**
 * Generates URL aliases for all nodes using /content-type/title-slug pattern.
 *
 * Run via: drush php:script scripts/generate-path-aliases.php
 */

declare(strict_types=1);

$storage = \Drupal::entityTypeManager()->getStorage('path_alias');
$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple();

$type_map = [
  'regulation_section' => 'regulations',
  'guidance_article'   => 'guidance',
  'enforcement_case'   => 'enforcement',
  'comparison'         => 'comparisons',
  'checklist'          => 'checklists',
  'glossary_term'      => 'glossary',
];

$created = 0;
$skipped = 0;

foreach ($nodes as $node) {
  $path = '/node/' . $node->id();

  // Skip if an alias already exists for this path.
  $existing = $storage->loadByProperties(['path' => $path]);
  if (!empty($existing)) {
    $skipped++;
    continue;
  }

  $type = $node->bundle();
  $prefix = $type_map[$type] ?? str_replace('_', '-', $type);
  $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $node->label()));
  $slug = trim($slug, '-');
  $alias = '/' . $prefix . '/' . $slug;

  $storage->create([
    'path'     => $path,
    'alias'    => $alias,
    'langcode' => $node->language()->getId(),
  ])->save();

  $created++;
}

echo "Path aliases generated: $created (skipped $skipped existing).\n";
