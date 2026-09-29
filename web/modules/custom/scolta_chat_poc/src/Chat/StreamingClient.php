<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * Streams a chat answer from the site's Scolta AI provider.
 *
 * scolta-php's AiClient has no streaming, so this sends the same request
 * AiClient would (same provider, key, model and endpoint, from
 * ScoltaConfig::toAiClientConfig()) with streaming switched on, and yields the
 * text as it arrives. It covers the two providers AiClient covers: Anthropic,
 * and OpenAI or any OpenAI compatible gateway (Amazee included).
 *
 * When a platform routes AI through its own layer (Drupal AI as the Scolta
 * provider, or the WordPress AI Client), streaming is skipped and answer()
 * falls back to the adapter's conversation(), which keeps that routing.
 *
 * Framework free on purpose: the Drupal module and the WordPress plugin share
 * this file.
 */
final class StreamingClient {

  private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';

  private const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

  private const ANTHROPIC_VERSION = '2023-06-01';

  private function __construct(
    private readonly string $provider,
    private readonly string $apiKey,
    private readonly string $model,
    private readonly string $url,
    private readonly ClientInterface $http,
  ) {}

  /**
   * Returns a client when the adapter's provider can be streamed, else NULL.
   */
  public static function forAdapter(AiServiceAdapter $ai, ?ClientInterface $http = NULL): ?self {
    // The WordPress AI Client, when present, is the path the adapter takes.
    if (class_exists('\WordPress\AI\Client')) {
      return NULL;
    }
    $config = $ai->getConfig()->toAiClientConfig();
    $provider = (string) ($config['provider'] ?? '');
    $key = (string) ($config['api_key'] ?? '');
    if (!in_array($provider, ['anthropic', 'openai'], TRUE) || $key === '') {
      return NULL;
    }
    $base = (string) ($config['base_url'] ?? '');
    if ($provider === 'openai') {
      $url = $base !== '' ? $base : self::OPENAI_URL;
      $path = parse_url($url, PHP_URL_PATH) ?? '/';
      if ($path === '' || $path === '/') {
        $url = rtrim($url, '/') . '/v1/chat/completions';
      }
    }
    else {
      $url = $base !== '' ? $base : self::ANTHROPIC_URL;
    }
    return new self($provider, $key, (string) ($config['model'] ?? ''), $url, $http ?? new Client());
  }

  /**
   * Yields the answer text in pieces as the provider sends it.
   *
   * @param array<int, array{role: string, content: string}> $messages
   *
   * @return \Generator<int, string>
   *
   * @throws \RuntimeException
   *   When the request fails or the provider reports an error.
   */
  public function stream(string $system, array $messages, int $maxTokens): \Generator {
    if ($this->provider === 'openai') {
      $body = [
        'model' => $this->model,
        'max_tokens' => $maxTokens,
        'stream' => TRUE,
        'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
      ];
      $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'];
    }
    else {
      $body = [
        'model' => $this->model,
        'max_tokens' => $maxTokens,
        'stream' => TRUE,
        'system' => $system,
        'messages' => $messages,
      ];
      $headers = [
        'x-api-key' => $this->apiKey,
        'anthropic-version' => self::ANTHROPIC_VERSION,
        'Content-Type' => 'application/json',
      ];
    }

    try {
      $response = $this->http->request('POST', $this->url, [
        'headers' => $headers + ['Accept' => 'text/event-stream'],
        'json' => $body,
        'stream' => TRUE,
        'connect_timeout' => 10,
        'read_timeout' => 60,
        'timeout' => 120,
      ]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Streaming request failed: ' . $e->getMessage(), 0, $e);
    }

    $stream = $response->getBody();
    $buffer = '';
    while (!$stream->eof()) {
      $buffer .= $stream->read(2048);
      while (($pos = strpos($buffer, "\n")) !== FALSE) {
        $line = rtrim(substr($buffer, 0, $pos), "\r");
        $buffer = substr($buffer, $pos + 1);
        $text = $this->parseLine($line);
        if ($text === NULL) {
          return;
        }
        if ($text !== '') {
          yield $text;
        }
      }
    }
    $text = $this->parseLine(rtrim($buffer, "\r\n"));
    if ($text !== NULL && $text !== '') {
      yield $text;
    }
  }

  /**
   * Parses one SSE line: the text it carries, '' for none, NULL at the end.
   */
  private function parseLine(string $line): ?string {
    if (!str_starts_with($line, 'data:')) {
      return '';
    }
    $data = trim(substr($line, 5));
    if ($data === '[DONE]') {
      return NULL;
    }
    $event = json_decode($data, TRUE);
    if (!is_array($event)) {
      return '';
    }
    if ($this->provider === 'openai') {
      if (isset($event['error'])) {
        throw new \RuntimeException('Provider error: ' . json_encode($event['error']));
      }
      return (string) ($event['choices'][0]['delta']['content'] ?? '');
    }
    return match ($event['type'] ?? '') {
      'content_block_delta' => (string) ($event['delta']['text'] ?? ''),
      'message_stop' => NULL,
      'error' => throw new \RuntimeException('Provider error: ' . json_encode($event['error'] ?? $event)),
      default => '',
    };
  }

  /**
   * The answer as pieces: streamed when possible, else one piece.
   *
   * A stream that fails before its first piece falls back to the adapter's
   * conversation(), so a provider that refuses streaming still answers. A
   * stream that fails part way ends there; what arrived stays.
   *
   * @return \Generator<int, string>
   */
  public static function answer(AiServiceAdapter $ai, string $system, array $messages, int $maxTokens, ?ClientInterface $http = NULL, ?callable $onFallback = NULL): \Generator {
    $client = self::forAdapter($ai, $http);
    if ($client === NULL && $onFallback !== NULL) {
      $onFallback('this provider cannot be streamed (provider "' . $ai->getConfig()->aiProvider . '"), answering in one piece');
    }
    if ($client !== NULL) {
      $sent = FALSE;
      try {
        foreach ($client->stream($system, $messages, $maxTokens) as $piece) {
          $sent = TRUE;
          yield $piece;
        }
        if ($sent) {
          return;
        }
        if ($onFallback !== NULL) {
          $onFallback('stream returned no text, answering in one piece');
        }
      }
      catch (\RuntimeException $e) {
        if ($onFallback !== NULL) {
          $onFallback(($sent ? 'stream broke off: ' : 'stream failed, answering in one piece: ') . $e->getMessage());
        }
        if ($sent) {
          return;
        }
      }
    }
    yield $ai->conversation($system, $messages, $maxTokens);
  }

}
