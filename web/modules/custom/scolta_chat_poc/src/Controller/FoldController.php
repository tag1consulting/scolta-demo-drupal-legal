<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\scolta_chat_poc\Chat\ContextAssembler;
use Drupal\scolta_chat_poc\Chat\ThreadStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Refreshes the running summary of the visitor's current thread.
 *
 * POST /scolta-chat-poc/fold -> {"folded": true, "ms": 1500}
 *
 * The browser calls this after each reply has arrived, so the summary model
 * call runs in a request of its own that nobody waits for, instead of before
 * the next answer. A request of its own works on any server; a shutdown
 * function would hold the reply's connection open under mod_php.
 *
 * Nothing to fold returns at once. If the next turn arrives before the fold
 * is saved, that turn folds for itself and this result is dropped, because
 * the thread it was computed from has changed.
 */
final class FoldController extends ControllerBase {

  public function __construct(
    private readonly AiServiceAdapter $aiService,
    private readonly ThreadStore $threads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('scolta_chat_poc.thread_store'),
    );
  }

  /**
   * Handles the fold request.
   */
  public function fold(): JsonResponse {
    $settings = $this->config('scolta_chat_poc.settings');
    $threadId = $this->threads->currentThreadId();
    if ($threadId === NULL || !$settings->get('enabled')) {
      return new JsonResponse(['folded' => FALSE]);
    }
    $assembler = new ContextAssembler(
      max(2, (int) $settings->get('window_messages')),
      max(20, (int) $settings->get('summary_words')),
      max(2000, (int) $settings->get('history_chars')),
    );
    $state = $this->threads->load($threadId);
    $evicted = $assembler->pendingFold($state);
    if ($evicted === []) {
      return new JsonResponse(['folded' => FALSE]);
    }
    $count = count($state->messages);
    $start = microtime(TRUE);
    $prompt = $assembler->foldPrompt($state, $evicted);
    try {
      $assembler->applyFold($state, $this->aiService->message($prompt['system'], $prompt['user'], 300));
    }
    catch (\Throwable $e) {
      $this->getLogger('scolta_chat_poc')->warning('Summary fold failed, kept the questions only: @msg', ['@msg' => $e->getMessage()]);
      $assembler->applyFallbackFold($state, $evicted);
    }
    $ms = (int) round((microtime(TRUE) - $start) * 1000);
    if (count($this->threads->load($threadId)->messages) !== $count) {
      return new JsonResponse(['folded' => FALSE, 'ms' => $ms, 'stale' => TRUE]);
    }
    $this->threads->save($threadId, $state);
    $this->getLogger('scolta_chat_poc')->info('Chat fold after reply: @ms ms', ['@ms' => $ms]);
    return new JsonResponse(['folded' => TRUE, 'ms' => $ms]);
  }

}
