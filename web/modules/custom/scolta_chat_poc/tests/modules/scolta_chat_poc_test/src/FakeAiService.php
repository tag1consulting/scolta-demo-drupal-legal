<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc_test;

use Drupal\Core\State\StateInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * A Scolta AI service that never calls a provider.
 *
 * Every call is recorded in state under scolta_chat_poc_test.calls. The
 * answer cites the first result URL it finds in the current turn.
 */
class FakeAiService extends AiServiceAdapter {

  public function __construct(
    private readonly StateInterface $state,
  ) {
    parent::__construct(ScoltaConfig::fromArray(['site_name' => 'ComplianceIQ Test']));
  }

  /**
   * {@inheritdoc}
   */
  public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string {
    $this->record('message', $systemPrompt, [['role' => 'user', 'content' => $userMessage]], $maxTokens);
    return 'Folded summary of earlier turns.';
  }

  /**
   * {@inheritdoc}
   */
  public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string {
    $this->record('conversation', $systemPrompt, $messages, $maxTokens);
    $last = end($messages)['content'] ?? '';
    if (preg_match('#^\[1\] .*\n(\S+)#m', $last, $m)) {
      return "The page says so [[1]]({$m[1]}).";
    }
    return 'Hello, ask me about the site.';
  }

  private function record(string $method, string $system, array $messages, int $maxTokens): void {
    $calls = $this->state->get('scolta_chat_poc_test.calls', []);
    $calls[] = ['method' => $method, 'system' => $system, 'messages' => $messages, 'max_tokens' => $maxTokens];
    $this->state->set('scolta_chat_poc_test.calls', $calls);
  }

}
