<?php
/**
 * Fixes nodes where body content was stored with a ```json {"body": ...}```
 * wrapper (an LLM generation artifact). Extracts the HTML body from the JSON
 * and updates each affected node.
 *
 * Run with: ddev drush php:script scripts/fix-json-bodies.php
 * Idempotent: only updates nodes that still have the JSON wrapper.
 */

use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;

$db = Database::getConnection();

// Find all node body revisions that start with the JSON code fence.
$result = $db->query(
  "SELECT DISTINCT entity_id FROM node__body WHERE body_value LIKE :prefix",
  [':prefix' => '```json%']
);

$nids = $result->fetchCol();

if (empty($nids)) {
  echo "No nodes with JSON-wrapped body found — nothing to fix.\n";
  return;
}

echo "Found " . count($nids) . " node(s) with JSON-wrapped body. Fixing...\n";

$fixed = 0;
$failed = 0;

foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    echo "  SKIP: Could not load node $nid\n";
    $failed++;
    continue;
  }

  $raw = $node->body->value;

  // Strip the opening ```json fence and trailing ``` fence.
  // Format: ```json\n{\n  "body": "HTML..."\n}\n```
  $stripped = preg_replace('/^```json\s*/s', '', $raw);
  $stripped = preg_replace('/\s*```\s*$/s', '', $stripped);

  $decoded = json_decode($stripped, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    // The LLM sometimes emits literal control characters (newlines, tabs) inside
    // the JSON string value, making it invalid. Extract the body with a regex
    // fallback that handles unescaped control characters and unescaped quotes.
    if (preg_match('/"body"\s*:\s*"(.*)/s', $stripped, $m)) {
      // Walk from the start of the value, collecting chars until the closing ".
      // A bare " that is not preceded by an odd run of backslashes ends the value.
      $rest  = $m[1];
      $html  = '';
      $i     = 0;
      $len   = strlen($rest);
      while ($i < $len) {
        $ch = $rest[$i];
        if ($ch === '\\' && $i + 1 < $len) {
          // Honour JSON escape sequences.
          $next = $rest[$i + 1];
          $html .= match ($next) {
            '"'  => '"',
            '\\' => '\\',
            '/'  => '/',
            'n'  => "\n",
            'r'  => "\r",
            't'  => "\t",
            default => $next,
          };
          $i += 2;
        }
        elseif ($ch === '"') {
          break; // End of string value.
        }
        else {
          $html .= $ch;
          $i++;
        }
      }
      $decoded = ['body' => $html];
    }
  }

  if (empty($decoded['body'])) {
    echo "  FAIL: node $nid — could not parse JSON: " . json_last_error_msg() . "\n";
    $failed++;
    continue;
  }

  $html = $decoded['body'];

  $node->body->value = $html;
  $node->body->format = 'full_html';
  $node->setNewRevision(false);
  $node->save();

  echo "  FIXED: node $nid (" . $node->bundle() . ") — " . strlen($html) . " bytes of HTML\n";
  $fixed++;
}

echo "\nDone. Fixed: $fixed, Failed: $failed\n";
