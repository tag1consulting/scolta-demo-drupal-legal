<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Keeps chat threads in the private tempstore.
 *
 * The private tempstore is scoped to the owner (user id, or the session for
 * an anonymous visitor), so a thread id guessed or copied from someone else
 * reads nothing.
 */
final class ThreadStore {

  private const COLLECTION = 'scolta_chat_poc';

  private const CURRENT_KEY = 'current_thread';

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Loads a thread, or an empty one when none is stored.
   */
  public function load(string $threadId): ThreadState {
    return ThreadState::fromArray($this->store()->get($this->key($threadId)));
  }

  /**
   * Saves a thread.
   */
  public function save(string $threadId, ThreadState $state): void {
    $this->store()->set($this->key($threadId), $state->toArray());
  }

  /**
   * Deletes a thread.
   */
  public function delete(string $threadId): void {
    $this->store()->delete($this->key($threadId));
  }

  /**
   * Returns the owner's current thread id, if one was recorded.
   */
  public function currentThreadId(): ?string {
    $id = $this->store()->get(self::CURRENT_KEY);
    return is_string($id) && $id !== '' ? $id : NULL;
  }

  /**
   * Records the owner's current thread id.
   */
  public function setCurrentThreadId(string $threadId): void {
    $this->store()->set(self::CURRENT_KEY, $threadId);
  }

  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get(self::COLLECTION);
  }

  private function key(string $threadId): string {
    return 'thread:' . $threadId;
  }

}
