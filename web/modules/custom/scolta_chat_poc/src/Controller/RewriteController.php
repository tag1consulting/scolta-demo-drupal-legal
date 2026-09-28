<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Rewrites a chat turn into a standalone search query.
 *
 * POST /scolta-chat-poc/rewrite
 *   {"message": "what about for contractors?",
 *    "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]}
 *   -> {"query": "GDPR breach notification contractors", "needs_search": true}
 *
 * The browser calls this before searching, so a follow up that leans on
 * earlier turns ("what about for contractors?") still searches for the
 * whole question. It never fails the turn: on any error it returns the
 * visitor's own words as the query.
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

    $lines = ['Earlier in the conversation:'];
    foreach ($history as $item) {
      $lines[] = ($item['role'] === 'user' ? 'Visitor: ' : 'Assistant: ') . $item['content'];
    }
    $lines[] = '';
    $lines[] = 'Latest visitor message: ' . $message;

    try {
      $raw = $this->aiService->message($this->systemPrompt(), implode("\n", $lines), 150);
      $parsed = self::parse($raw);
    }
    catch (\Throwable $e) {
      $this->getLogger('scolta_chat_poc')->warning('Rewrite failed, searching the raw message: @msg', ['@msg' => $e->getMessage()]);
      $parsed = NULL;
    }
    return new JsonResponse($parsed ? $parsed + ['rewritten' => TRUE] : $fallback);
  }

  /**
   * Parses the model's JSON reply, tolerating a code fence around it.
   *
   * @return array{query: string, needs_search: bool}|null
   *   The parsed rewrite, or NULL when the reply is unusable.
   */
  public static function parse(string $raw): ?array {
    if (!preg_match('/\{.*\}/s', $raw, $match)) {
      return NULL;
    }
    $decoded = json_decode($match[0], TRUE);
    if (!is_array($decoded) || !is_string($decoded['query'] ?? NULL)) {
      return NULL;
    }
    $query = trim(preg_replace('/\s+/u', ' ', $decoded['query']) ?? '');
    $needsSearch = ($decoded['needs_search'] ?? TRUE) !== FALSE;
    if ($query === '' && $needsSearch) {
      return NULL;
    }
    return ['query' => mb_substr($query, 0, 200), 'needs_search' => $needsSearch];
  }

  private function systemPrompt(): string {
    $site = $this->aiService->getConfig()->siteName ?: 'the website';
    return "You turn the latest message in a conversation with the search assistant of {$site} into one standalone query for that site's search engine.\n"
      . "Rules:\n"
      . "- Resolve pronouns and ellipsis from the earlier turns. \"What about for contractors?\" after a question about breach notification under GDPR becomes \"GDPR breach notification contractors\".\n"
      . "- When the latest message changes topic, search the new topic only and drop the old one.\n"
      . "- Keep the query short: the key terms, regulation names and entities, no filler, at most 12 words.\n"
      . "- Never answer the question.\n"
      . "- Set needs_search to false only for greetings, thanks, small talk, or remarks about the conversation itself that need no facts from the site. Anything that asks for information needs a search.\n"
      . "Reply with JSON only, no prose: {\"query\": \"...\", \"needs_search\": true}";
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
