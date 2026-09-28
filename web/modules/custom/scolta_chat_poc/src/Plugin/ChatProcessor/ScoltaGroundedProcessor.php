<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Plugin\ChatProcessor;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\ChatProcessor;
use Drupal\ai\Base\ChatProcessorBase;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\scolta_chat_poc\Chat\ContextAssembler;
use Drupal\scolta_chat_poc\Chat\PromptBuilder;
use Drupal\scolta_chat_poc\Chat\ResultsMarkup;
use Drupal\scolta_chat_poc\Chat\ThreadState;
use Drupal\scolta_chat_poc\Chat\ThreadStore;
use Drupal\scolta_chat_poc\Chat\TurnPayload;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Answers each chat turn from that turn's Scolta search results.
 *
 * The browser searches the site before posting the turn (Scolta search runs
 * only there) and sends the results as `scolta` in the request body. This
 * processor keeps the thread server side, bounds the context, asks the
 * site's Scolta AI service for a cited answer, and renders the results under
 * it.
 *
 * Budgets come from scolta_chat_poc.settings, never from the plugin
 * configuration: DeepChat posts plugin_configuration from the browser, so
 * anything read from it is client controlled.
 */
#[ChatProcessor(
  id: 'scolta_grounded',
  label: new TranslatableMarkup('Scolta grounded chat'),
  description: new TranslatableMarkup('Every turn runs a Scolta search in the browser; the answer is grounded in and cites those results, which are shown under it.'),
)]
class ScoltaGroundedProcessor extends ChatProcessorBase implements ContainerFactoryPluginInterface {

  private const FLOOD_EVENT = 'scolta_chat_poc.turn';

  private const MAX_QUESTION = 2000;

  /**
   * This turn's payload, kept for getPostResponseMarkup().
   */
  protected ?TurnPayload $payload = NULL;

  /**
   * Result numbers this turn's answer cited.
   *
   * @var int[]
   */
  protected array $cited = [];

  /**
   * Whether the results block should render for this turn.
   */
  protected bool $showResults = FALSE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly AiServiceAdapter $aiService,
    protected readonly ThreadStore $threads,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly RequestStack $requestStack,
    protected readonly FloodInterface $flood,
    protected readonly AccountInterface $currentUser,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('scolta.ai_service'),
      $container->get('scolta_chat_poc.thread_store'),
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('flood'),
      $container->get('current_user'),
      $container->get('uuid'),
      $container->get('logger.factory')->get('scolta_chat_poc'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function allowsImages(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getThreadId(): ?string {
    if ($this->threadId) {
      return $this->threadId;
    }
    if ($this->hasOwner()) {
      $current = $this->threads->currentThreadId();
      if ($current !== NULL) {
        return $this->threadId = $current;
      }
    }
    // Not persisted yet: the block renders this id on every page, and writing
    // it here would start a session for every anonymous visitor. The first
    // turn records it.
    return $this->threadId = $this->uuid->generate();
  }

  /**
   * {@inheritdoc}
   */
  public function resetThread($thread_id): string {
    if ($this->hasOwner()) {
      $this->threads->delete((string) $thread_id);
    }
    $this->threadId = $this->uuid->generate();
    if ($this->hasOwner()) {
      $this->threads->setCurrentThreadId($this->threadId);
    }
    return $this->threadId;
  }

  /**
   * {@inheritdoc}
   */
  public function getMessageHistory(): array {
    if (!$this->hasOwner()) {
      return [];
    }
    $state = $this->threads->load((string) $this->getThreadId());
    return array_map(static fn (array $message): array => [
      'role' => $message['role'],
      'message' => $message['content'],
      'timestamp' => $message['timestamp'],
    ], $state->messages);
  }

  /**
   * {@inheritdoc}
   */
  public function doExecute(): ChatOutput {
    $settings = $this->configFactory->get('scolta_chat_poc.settings');
    $siteName = $this->siteName();

    if (!$settings->get('enabled')) {
      return $this->reply((string) new TranslatableMarkup('The chat is switched off on this site.'));
    }
    if (!$this->floodAllows()) {
      return $this->reply((string) new TranslatableMarkup('Too many messages in a short time. Wait a minute and try again.'));
    }

    $question = $this->latestUserMessage();
    if ($question === '') {
      throw new \InvalidArgumentException('No user message found in input.');
    }

    $threadId = (string) $this->getThreadId();
    $state = $this->threads->load($threadId);
    $this->threads->setCurrentThreadId($threadId);

    $request = $this->requestStack->getCurrentRequest();
    $body = Json::decode((string) $request?->getContent()) ?: [];
    $contextChars = max(500, (int) $settings->get('context_chars'));
    $this->payload = TurnPayload::fromRequest($body['scolta'] ?? NULL, $question, $contextChars, (string) $request?->getHost());

    $assembler = new ContextAssembler(
      max(2, (int) $settings->get('window_messages')),
      max(20, (int) $settings->get('summary_words')),
      max(2000, (int) $settings->get('history_chars')),
    );
    if ($this->payload->seed !== NULL) {
      $assembler->applySeed($state, $this->payload->seed);
    }

    $ceiling = max(1, (int) $settings->get('turn_ceiling'));
    if ($state->turns >= $ceiling) {
      return $this->reply((string) new TranslatableMarkup('This conversation has reached its length limit. Start a fresh conversation from the chat menu to keep going.'));
    }

    $stats = [
      'thread' => substr(hash('sha256', $threadId), 0, 12),
      'turn' => $state->turns + 1,
      'query' => $this->payload->query,
      'needs_search' => $this->payload->needsSearch,
      'results' => count($this->payload->results),
      'context_chars' => mb_strlen($this->payload->context),
      'repeated_excerpts' => count(array_filter($this->payload->results, static fn (array $r): bool => isset($state->sentUrls[$r['url']]))),
      'fold_ms' => 0,
    ];

    // Slide the window: fold what left it into the running summary.
    $evicted = $assembler->pendingFold($state);
    if ($evicted !== []) {
      $start = microtime(TRUE);
      $prompt = $assembler->foldPrompt($state, $evicted);
      try {
        $assembler->applyFold($state, $this->aiService->message($prompt['system'], $prompt['user'], 300));
      }
      catch (\Throwable $e) {
        $this->logger->warning('Summary fold failed, kept the questions only: @msg', ['@msg' => $e->getMessage()]);
        $assembler->applyFallbackFold($state, $evicted);
      }
      $stats['fold_ms'] = (int) round((microtime(TRUE) - $start) * 1000);
    }

    if ($this->payload->needsSearch && $this->payload->results === []) {
      // Nothing to ground an answer in. Answering anyway would come from
      // general knowledge, so this reply is fixed and costs no model call.
      $answer = (string) new TranslatableMarkup('I searched @site for "@query" and found nothing that answers this, so I can\'t answer it from the site. Try asking with the specific regulation, term or document you have in mind.', [
        '@site' => $siteName,
        '@query' => $this->payload->query,
      ]);
      $this->showResults = TRUE;
      $stats += ['prompt_chars' => 0, 'answer_ms' => 0, 'cited' => [], 'dropped' => 0, 'mode' => 'no_results'];
    }
    else {
      $mode = $this->payload->needsSearch ? PromptBuilder::MODE_GROUNDED : PromptBuilder::MODE_SMALL_TALK;
      $system = PromptBuilder::system(
        $this->aiService->getFollowUpPrompt(),
        $siteName,
        $state->summary,
        $assembler->priorSources($state),
        $mode,
      );
      $assembled = $assembler->assemble($state, PromptBuilder::userTurn($question, $this->payload));
      $promptChars = mb_strlen($system);
      foreach ($assembled['messages'] as $message) {
        $promptChars += mb_strlen($message['content']);
      }

      $start = microtime(TRUE);
      try {
        $answer = trim($this->aiService->conversation($system, $assembled['messages'], max(100, (int) $settings->get('answer_tokens'))));
      }
      catch (\Throwable $e) {
        $this->logger->error('Chat answer failed: @msg', ['@msg' => $e->getMessage()]);
        return $this->reply((string) new TranslatableMarkup('The AI service did not answer. Try again in a moment.'));
      }
      $this->cited = ResultsMarkup::citedNumbers($answer, $this->payload->results);
      $this->showResults = $this->payload->needsSearch;
      $stats += [
        'prompt_chars' => $promptChars,
        'system_chars' => mb_strlen($system),
        'answer_ms' => (int) round((microtime(TRUE) - $start) * 1000),
        'cited' => $this->cited,
        'dropped' => $assembled['dropped'],
        'mode' => $mode,
      ];
    }

    $this->record($state, $question, $answer);
    $this->threads->save($threadId, $state);

    $this->logger->info('Chat turn: @stats', ['@stats' => Json::encode($stats)]);
    return $this->reply($answer);
  }

  /**
   * {@inheritdoc}
   */
  public function getPostResponseMarkup(): string {
    if (!$this->showResults || $this->payload === NULL) {
      return '';
    }
    return ResultsMarkup::render($this->payload->query, $this->payload->results, $this->cited, $this->siteName());
  }

  /**
   * Writes the turn into the thread: the visitor's words, never the excerpts.
   */
  protected function record(ThreadState $state, string $question, string $answer): void {
    $state->addMessage('user', $question);
    $state->addMessage('assistant', $answer);
    $state->turns++;
    foreach ($this->payload->results as $result) {
      $state->sentUrls[$result['url']] = TRUE;
      if (in_array($result['n'], $this->cited, TRUE)) {
        // Re-inserting moves a page cited again to the recent end.
        unset($state->citedSources[$result['url']]);
        $state->citedSources[$result['url']] = ['title' => $result['title'], 'url' => $result['url']];
      }
    }
  }

  protected function reply(string $text): ChatOutput {
    return new ChatOutput(new ChatMessage('assistant', $text), [$text], []);
  }

  protected function latestUserMessage(): string {
    $messages = $this->getInput()?->getMessages() ?? [];
    for ($i = count($messages) - 1; $i >= 0; $i--) {
      if ($messages[$i]->getRole() === 'user') {
        return trim(mb_substr($messages[$i]->getText(), 0, self::MAX_QUESTION));
      }
    }
    return '';
  }

  protected function siteName(): string {
    $name = $this->aiService->getConfig()->siteName;
    return $name !== '' ? $name : (string) $this->configFactory->get('system.site')->get('name');
  }

  /**
   * Whether this request has a tempstore owner to read threads for.
   *
   * Anonymous visitors own a thread only once DeepChat has started their
   * session; reading before that would start one on every page view.
   */
  protected function hasOwner(): bool {
    if ($this->currentUser->isAuthenticated()) {
      return TRUE;
    }
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL && $request->hasSession() && $request->hasPreviousSession();
  }

  /**
   * Per IP and site wide limits, sharing Scolta's AI flood settings.
   */
  protected function floodAllows(): bool {
    $config = $this->configFactory->get('scolta.settings');
    $limit = (int) ($config->get('flood.ai_ip_limit') ?? 60);
    $window = (int) ($config->get('flood.ai_ip_window') ?? 60);
    if ($limit <= 0) {
      return TRUE;
    }
    $ip = (string) $this->requestStack->getCurrentRequest()?->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window, $ip)) {
      return FALSE;
    }
    $this->flood->register(self::FLOOD_EVENT, $window, $ip);
    return TRUE;
  }

}
