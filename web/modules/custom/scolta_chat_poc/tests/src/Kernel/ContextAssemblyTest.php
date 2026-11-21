<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta_chat_poc\Kernel;

use PHPUnit\Framework\Attributes\Group;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\scolta_chat_poc\Chat\ContextAssembler;
use Drupal\scolta_chat_poc\Chat\ThreadState;
use Drupal\scolta_chat_poc\Chat\ThreadStore;
use Drupal\user\Entity\User;

/**
 * Tests how much of a thread the model sees on each turn.
 */
#[Group('scolta_chat_poc')]
final class ContextAssemblyTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'scolta_chat_poc'];

  /**
   * Builds a thread of $pairs exchanges.
   */
  private function thread(int $pairs, int $length = 10): ThreadState {
    $state = new ThreadState();
    for ($i = 1; $i <= $pairs; $i++) {
      $state->addMessage('user', "Q{$i} " . str_repeat('q', $length));
      $state->addMessage('assistant', "A{$i} " . str_repeat('a', $length));
    }
    return $state;
  }

  /**
   * The last six messages travel verbatim, then the current turn.
   */
  public function testWindowKeepsLastMessagesVerbatim(): void {
    $state = $this->thread(5);
    $assembled = (new ContextAssembler(6, 120, 12000))->assemble($state, 'current turn');

    $contents = array_column($assembled['messages'], 'content');
    $this->assertCount(7, $contents);
    $this->assertStringStartsWith('Q3 ', $contents[0]);
    $this->assertStringStartsWith('A5 ', $contents[5]);
    $this->assertSame('current turn', $contents[6]);
    $this->assertSame(0, $assembled['dropped']);
  }

  /**
   * Messages that leave the window are folded once, and only once.
   */
  public function testSummaryFoldsWhenWindowSlides(): void {
    $assembler = new ContextAssembler(6, 5, 12000);
    $state = $this->thread(3);
    $this->assertSame([], $assembler->pendingFold($state), 'Nothing to fold while the thread fits the window.');

    $state->addMessage('user', 'Q4');
    $state->addMessage('assistant', 'A4');
    $pending = $assembler->pendingFold($state);
    $this->assertSame(['Q1', 'A1'], array_map(static fn (array $m): string => strtok($m['content'], ' '), $pending));

    $prompt = $assembler->foldPrompt($state, $pending);
    $this->assertStringContainsString('at most 5 words', $prompt['system']);
    $this->assertStringContainsString('Visitor: Q1', $prompt['user']);

    $assembler->applyFold($state, 'one two three four five six seven');
    $this->assertSame('one two three four five', $state->summary, 'The summary is cut to the word limit.');
    $this->assertSame(2, $state->folded);
    $this->assertSame([], $assembler->pendingFold($state), 'A fold is not repeated until the window slides again.');

    $state->addMessage('user', 'Q5');
    $state->addMessage('assistant', 'A5');
    $this->assertCount(2, $assembler->pendingFold($state), 'Each slide folds just the pair that left.');
  }

  /**
   * A failed fold keeps the visitor's questions.
   */
  public function testFallbackFoldKeepsQuestions(): void {
    $assembler = new ContextAssembler(2, 120, 12000);
    $state = $this->thread(2);
    $assembler->applyFallbackFold($state, $assembler->pendingFold($state));
    $this->assertStringContainsString('Q1', $state->summary);
    $this->assertStringNotContainsString('A1', $state->summary);
    $this->assertSame(2, $state->folded);
  }

  /**
   * The backstop follows truncate_conversation and never drops the turn.
   */
  public function testBackstopDropsOldestPairsAfterTheFirstTwo(): void {
    // The scolta-core test case: preserve two, drop the oldest pair.
    $messages = [
      ['role' => 'system', 'content' => 'You are helpful.'],
      ['role' => 'user', 'content' => 'Initial context.'],
      ['role' => 'user', 'content' => 'Old question?'],
      ['role' => 'assistant', 'content' => 'Old answer.'],
      ['role' => 'user', 'content' => 'New question?'],
      ['role' => 'assistant', 'content' => 'New answer.'],
    ];
    $out = ContextAssembler::truncate($messages, 60, 2, 2, 0);
    $this->assertSame(['You are helpful.', 'Initial context.', 'New question?', 'New answer.'], array_column($out, 'content'));

    // Through the assembler: a huge current turn empties the middle but
    // keeps the first two messages and itself.
    $state = $this->thread(3, 1000);
    $assembled = (new ContextAssembler(6, 120, 3000))->assemble($state, str_repeat('x', 2000));
    $contents = array_column($assembled['messages'], 'content');
    $this->assertCount(3, $contents);
    $this->assertStringStartsWith('Q1 ', $contents[0]);
    $this->assertStringStartsWith('A1 ', $contents[1]);
    $this->assertSame(str_repeat('x', 2000), $contents[2]);
    $this->assertSame(4, $assembled['dropped']);
  }

  /**
   * Prior sources are the most recent cited pages, numbered.
   */
  public function testPriorSourcesAreRecentAndNumbered(): void {
    $state = new ThreadState();
    for ($i = 1; $i <= ContextAssembler::PRIOR_SOURCES_LIMIT + 2; $i++) {
      $state->citedSources["/page-{$i}"] = ['title' => "Page {$i}", 'url' => "/page-{$i}"];
    }
    $sources = (new ContextAssembler())->priorSources($state);
    $this->assertCount(ContextAssembler::PRIOR_SOURCES_LIMIT, $sources);
    $this->assertSame(['n' => 1, 'title' => 'Page 3', 'url' => '/page-3'], $sources[0]);
    $this->assertSame('/page-10', end($sources)['url']);
  }

  /**
   * A search seed opens an empty thread and is ignored otherwise.
   */
  public function testSeedOpensOnlyAnEmptyThread(): void {
    $assembler = new ContextAssembler();
    $seed = [
      'query' => 'breach notification',
      'summary' => 'GDPR requires notice within a deadline [1].',
      'results' => [['n' => 1, 'title' => 'Article 33', 'url' => '/gdpr/33', 'excerpt' => '']],
    ];

    $state = new ThreadState();
    $this->assertTrue($assembler->applySeed($state, $seed));
    $this->assertSame(['user', 'assistant'], array_column($state->messages, 'role'));
    $this->assertSame('breach notification', $state->messages[0]['content']);
    $this->assertSame($seed['summary'], $state->messages[1]['content']);
    $this->assertTrue($state->seeded);
    $this->assertSame(0, $state->turns, 'The seed exchange is not a visitor turn.');
    $this->assertSame('/gdpr/33', $assembler->priorSources($state)[0]['url']);

    $assembled = $assembler->assemble($state, 'what about contractors?');
    $this->assertSame(['breach notification', $seed['summary'], 'what about contractors?'], array_column($assembled['messages'], 'content'));

    $busy = $this->thread(1);
    $this->assertFalse($assembler->applySeed($busy, $seed));
    $this->assertCount(2, $busy->messages);
  }

  /**
   * Threads round trip through the private tempstore, per owner.
   */
  public function testThreadStoreKeepsThreadsPerOwner(): void {
    $this->installEntitySchema('user');
    $alice = User::create(['name' => 'alice']);
    $alice->save();
    $bob = User::create(['name' => 'bob']);
    $bob->save();

    /** @var \Drupal\scolta_chat_poc\Chat\ThreadStore $store */
    $store = $this->container->get('scolta_chat_poc.thread_store');
    $this->assertInstanceOf(ThreadStore::class, $store);

    $this->setCurrentUser($alice);
    $state = $this->thread(1);
    $state->summary = 'kept';
    $store->save('t1', $state);
    $store->setCurrentThreadId('t1');
    $this->assertSame('kept', $store->load('t1')->summary);
    $this->assertSame('t1', $store->currentThreadId());

    $this->setCurrentUser($bob);
    $this->assertSame([], $store->load('t1')->messages, 'Another owner cannot read the thread.');
    $this->assertNull($store->currentThreadId());

    $this->setCurrentUser($alice);
    $store->delete('t1');
    $this->assertSame([], $store->load('t1')->messages);
  }

}
