<?php
/**
 * Creates the "About This Demo" page for ComplianceIQ.
 * Run with: ddev drush php:script scripts/setup-about-page.php
 * Idempotent: skips creation if the page already exists.
 */

use Drupal\node\Entity\Node;

$existing = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->loadByProperties(['type' => 'page', 'title' => 'About This Demo']);

if ($existing) {
  echo "About This Demo page already exists — skipping.\n";
  return;
}

$body = <<<'HTML'
<h2>About This Site</h2>
<p><strong>ComplianceIQ is a fictional legal compliance platform.</strong> It was created by Tag1 Consulting to demonstrate the capabilities of Scolta, an open-source AI-powered search platform, on a content-rich Drupal 11 regulatory and compliance knowledge base.</p>

<h2>What You Are Looking At</h2>
<p>This site is a Drupal 11 demonstration built to show how Scolta performs on technical legal and regulatory content. The site contains hundreds of compliance articles, regulations, and guidance documents covering topics including:</p>
<ul>
  <li>GDPR, CCPA, and global data privacy frameworks</li>
  <li>Data breach notification requirements by jurisdiction</li>
  <li>Financial services compliance (SOX, Basel III, DORA)</li>
  <li>Healthcare regulations (HIPAA, FDA, MDR)</li>
  <li>AI governance and emerging regulatory frameworks</li>
  <li>Cross-border data transfer mechanisms</li>
</ul>
<p>All content was generated to be representative of real compliance topics. The regulatory details reflect actual frameworks, though this site should not be used as legal advice.</p>

<h2>What Scolta Does Here</h2>
<p>The search bar uses Scolta to let you explore compliance topics using natural language rather than keyword matching. Try these example queries:</p>
<ul>
  <li>"GDPR data breach notification timeline requirements"</li>
  <li>"What are the penalties for CCPA violations?"</li>
  <li>"How does DORA affect financial institutions in the EU?"</li>
  <li>"HIPAA minimum necessary standard explained"</li>
  <li>"Cross-border data transfer mechanisms after Schrems II"</li>
</ul>
<p>Scolta uses Pagefind for full-text indexing, Claude via the Anthropic API for query expansion and AI-generated overviews, and a custom BM25-based scoring layer tuned for legal and regulatory vocabulary.</p>

<h2>About Tag1 Consulting</h2>
<p>Tag1 Consulting is one of the leading Drupal development and consulting firms in the world. Tag1 built and open-sources Scolta as a demonstration of what AI-augmented content discovery can look like on modern Drupal sites. For more information about Tag1 and Scolta, visit <a href="https://tag1.com">tag1.com</a>.</p>

<h2>Reuse and Attribution</h2>
<p>If you are evaluating Scolta for your organization and have questions about how this demo was built or how to implement Scolta for your use case, contact Tag1 Consulting.</p>
HTML;

$node = Node::create([
  'type'     => 'page',
  'title'    => 'About This Demo',
  'langcode' => 'en',
  'status'   => 1,
  'uid'      => 1,
  'body'     => ['value' => $body, 'format' => 'full_html'],
  'path'     => [['alias' => '/about/demo']],
]);
$node->save();

echo "Created 'About This Demo' at /about/demo (node/" . $node->id() . ")\n";
