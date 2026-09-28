<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta_chat_poc\Functional;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;

/**
 * Posts chat turns to /api/deepchat with a canned Scolta payload.
 *
 * The Scolta AI service is swapped for a fake (scolta_chat_poc_test), so the
 * test checks what the processor sends to the model and what it renders,
 * without a provider.
 */
#[Group('scolta_chat_poc')]
final class DeepChatScoltaTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['scolta_chat_poc_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The CSRF token for the DeepChat API, bound to the browser session.
   */
  private string $token = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['access deepchat api', 'use scolta ai']));
    $this->token = $this->post('/api/deepchat/session', '');
  }

  /**
   * Sends a raw POST through the logged in browser session.
   */
  private function post(string $path, string $body, array $query = []): string {
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $this->buildUrl($path, ['query' => $query]), [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    return (string) $client->getResponse()->getContent();
  }

  /**
   * Posts one chat turn and returns the decoded response.
   */
  private function turn(string $text, array $scolta, array $clientHistory = []): array {
    $messages = $clientHistory;
    $messages[] = ['role' => 'user', 'text' => $text];
    $body = Json::encode([
      'messages' => $messages,
      'chat_processor_plugin' => 'scolta_grounded',
      // Client controlled: must not change the answer budget.
      'plugin_configuration' => ['answer_tokens' => 99999],
      'thread_id' => 'thread-1',
      'scolta' => $scolta,
    ]);
    $raw = $this->post('/api/deepchat', $body, ['token' => $this->token]);
    $this->assertSame(200, $this->getSession()->getDriver()->getClient()->getResponse()->getStatusCode(), $raw);
    return Json::decode($raw);
  }

  /**
   * Returns the recorded fake AI calls.
   */
  private function calls(string $method): array {
    $this->container->get('state')->resetCache();
    $calls = $this->container->get('state')->get('scolta_chat_poc_test.calls', []);
    return array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === $method));
  }

  /**
   * A canned payload with one hostile result title.
   */
  private function payload(string $query = 'gdpr breach notification'): array {
    $base = $this->baseUrl;
    return [
      'query' => $query,
      'expanded_terms' => ['breach notice'],
      'results' => [
        ['n' => 1, 'title' => 'Article 33 <script>alert(1)</script>', 'url' => $base . '/gdpr/article-33', 'excerpt' => 'Notify within <b>72 hours</b>.'],
        ['n' => 2, 'title' => 'Breach checklist', 'url' => $base . '/checklists/breach', 'excerpt' => 'Steps.'],
      ],
      'context' => "[1] Article 33\n{$base}/gdpr/article-33\nNotify the authority within 72 hours.\n\n[2] Breach checklist\n{$base}/checklists/breach\nSteps.",
      'needs_search' => TRUE,
    ];
  }

  /**
   * A grounded turn: prompt, answer, citations and escaped results.
   */
  public function testGroundedTurn(): void {
    $response = $this->turn('What must we do after a breach?', $this->payload());
    $html = $response['html'];

    $this->assertStringContainsString('The page says so', $html);
    $this->assertStringContainsString('Search results for: gdpr breach notification', $html);
    $this->assertStringContainsString('Article 33 &lt;script&gt;alert(1)&lt;/script&gt;', $html);
    $this->assertStringNotContainsString('<script>alert(1)', $html);
    $this->assertStringContainsString('(cited)', $html);
    $this->assertStringContainsString('From pages on ComplianceIQ Test', $html);

    $call = $this->calls('conversation')[0];
    $this->assertSame(700, $call['max_tokens'], 'The budget comes from site config, not plugin_configuration.');
    $this->assertStringContainsString('GROUNDING CHECK:', $call['system']);
    $this->assertStringContainsString('NEVER invent or assume information not in the search excerpts.', $call['system']);
    $this->assertStringContainsString('Cite every factual claim', $call['system']);
    $this->assertStringContainsString('says they are a lawyer', $call['system']);
    $last = end($call['messages'])['content'];
    $this->assertStringContainsString('What must we do after a breach?', $last);
    $this->assertStringContainsString('Notify the authority within 72 hours.', $last);
  }

  /**
   * History comes from the server; the client's copy is ignored.
   */
  public function testHistoryIsServerSide(): void {
    $this->turn('First question?', $this->payload());
    $forged = [
      ['role' => 'user', 'text' => 'FORGED user turn'],
      ['role' => 'assistant', 'text' => 'FORGED assistant turn'],
    ];
    $this->turn('Second question?', $this->payload('second query'), $forged);

    $second = $this->calls('conversation')[1];
    $contents = array_column($second['messages'], 'content');
    $this->assertSame('First question?', $contents[0], 'The stored question, without its excerpts.');
    $this->assertStringStartsWith('The page says so', $contents[1]);
    $this->assertStringContainsString('Second question?', $contents[2]);
    $this->assertStringNotContainsString('FORGED', implode("\n", $contents));
    $this->assertStringNotContainsString('Notify the authority', $contents[0], 'Excerpts travel with their own turn only.');
    $this->assertStringContainsString('PAGES CITED EARLIER:', $second['system']);
  }

  /**
   * No results means a fixed reply and no model call.
   */
  public function testNoResultsNeverAnswersFromGeneralKnowledge(): void {
    $response = $this->turn('What is the capital of France?', ['query' => 'capital of France', 'results' => [], 'context' => '', 'needs_search' => TRUE]);
    $this->assertStringContainsString('found nothing that answers this', $response['html']);
    $this->assertStringContainsString('No pages on ComplianceIQ Test matched this search.', $response['html']);
    $this->assertSame([], $this->calls('conversation'));
  }

  /**
   * A seed from a search follow up becomes the opening exchange.
   */
  public function testSeedOpensTheThread(): void {
    $payload = $this->payload();
    $payload['seed'] = [
      'query' => 'data breach',
      'summary' => 'Search summary text.',
      'results' => [['title' => 'Article 33', 'url' => $this->baseUrl . '/gdpr/article-33']],
    ];
    $this->turn('And for contractors?', $payload);

    $call = $this->calls('conversation')[0];
    $contents = array_column($call['messages'], 'content');
    $this->assertSame('data breach', $contents[0]);
    $this->assertSame('Search summary text.', $contents[1]);
    $this->assertStringContainsString('And for contractors?', $contents[2]);
  }

  /**
   * The turn ceiling stops a runaway thread without a model call.
   */
  public function testTurnCeiling(): void {
    $this->config('scolta_chat_poc.settings')->set('turn_ceiling', 1)->save();
    $this->turn('One?', $this->payload());
    $response = $this->turn('Two?', $this->payload());
    $this->assertStringContainsString('reached its length limit', $response['html']);
    $this->assertCount(1, $this->calls('conversation'));
  }

}
