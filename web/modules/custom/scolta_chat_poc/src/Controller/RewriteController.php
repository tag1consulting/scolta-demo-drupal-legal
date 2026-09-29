<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\scolta_chat_poc\Chat\QueryPlan;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Plans the search for a follow up: a standalone query plus its expansion.
 *
 * POST /scolta-chat-poc/rewrite
 *   {"message": "what about for contractors?",
 *    "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]}
 *   -> {"query": "GDPR breach notification contractors", "needs_search": true,
 *       "terms": ["subprocessor breach", "processor obligations"]}
 *
 * One model call (see QueryPlan) replaces the rewrite followed by Scolta's
 * expansion. It runs on the expansion model at temperature 0, like Scolta's
 * own expansion. It never fails the turn: on any error it returns the
 * visitor's own words as the query and no terms, and the browser then asks
 * Scolta's expand-query endpoint as on a first turn.
 */
final class RewriteController extends ControllerBase {

  private const FLOOD_EVENT = 'scolta_chat_poc.rewrite';

  private const MAX_MESSAGE = 1000;

  private const MAX_HISTORY_ITEM = 1200;

  public function __construct(
    private readonly AiServiceAdapter $aiService,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('scolta.ai_service'),
      $container->get('flood'),
    );
  }

  /**
   * Handles the rewrite request.
   */
  public function rewrite(Request $request): JsonResponse {
    $data = Json::decode((string) $request->getContent());
    $message = is_array($data) && is_string($data['message'] ?? NULL) ? trim(mb_substr($data['message'], 0, self::MAX_MESSAGE)) : '';
    if ($message === '') {
      return new JsonResponse(['error' => 'No message.'], 400);
    }

    $history = [];
    // The previous two exchanges at most.
    foreach (array_slice(is_array($data['history'] ?? NULL) ? $data['history'] : [], -4) as $item) {
      if (is_array($item) && in_array($item['role'] ?? '', ['user', 'assistant'], TRUE) && is_string($item['content'] ?? NULL)) {
        $history[] = ['role' => $item['role'], 'content' => trim(mb_substr($item['content'], 0, self::MAX_HISTORY_ITEM))];
      }
    }
    $fallback = ['query' => $message, 'needs_search' => TRUE, 'rewritten' => FALSE];
    if ($history === []) {
      return new JsonResponse($fallback);
    }
    if (!$this->floodAllows($request)) {
      return new JsonResponse($fallback);
    }

    try {
      $system = QueryPlan::systemPrompt((string) $this->aiService->getConfig()->siteName, $this->aiService->getExpandPrompt());
      $raw = $this->aiService->messageForOperation('expand_query', $system, QueryPlan::userMessage($message, $history), 400);
      $parsed = QueryPlan::parse($raw);
    }
    catch (\Throwable $e) {
      $this->getLogger('scolta_chat_poc')->warning('Rewrite failed, searching the raw message: @msg', ['@msg' => $e->getMessage()]);
      $parsed = NULL;
    }
    return new JsonResponse($parsed ? $parsed + ['rewritten' => TRUE] : $fallback);
  }

  private function floodAllows(Request $request): bool {
    $config = $this->config('scolta.settings');
    $limit = (int) ($config->get('flood.ai_ip_limit') ?? 60);
    $window = (int) ($config->get('flood.ai_ip_window') ?? 60);
    if ($limit <= 0) {
      return TRUE;
    }
    $ip = (string) $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window, $ip)) {
      return FALSE;
    }
    $this->flood->register(self::FLOOD_EVENT, $window, $ip);
    return TRUE;
  }

}
