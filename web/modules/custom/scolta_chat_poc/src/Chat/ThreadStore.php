<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Chat;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps chat threads in the private tempstore.
 *
 * The private tempstore is scoped to the owner (user id, or the session for
 * an anonymous visitor), so a thread id guessed or copied from someone else
 * reads nothing.
 *
 * A streamed reply is recorded after the response has started, and the
 * summary is refreshed after it has ended. By then Drupal has saved and
 * closed the session, and PrivateTempStore, which reads the anonymous owner
 * from the session on every call, would try to reopen it and fail ("headers
 * already sent"). So bindOwner() resolves the owner once, while the request
 * is live, and from then on this store reads and writes the tempstore's own
 * key value collection directly, in the tempstore's own record format.
 */
final class ThreadStore {

  private const COLLECTION = 'scolta_chat_poc';

  private const CURRENT_KEY = 'current_thread';

  private const OWNER_SESSION_KEY = 'core.tempstore.private.owner';

  private ?string $owner = NULL;

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
    private readonly AccountInterface $currentUser,
    private readonly RequestStack $requestStack,
    private readonly int $expire,
  ) {}

  /**
   * Resolves the owner now, so later calls never touch the session.
   *
   * Must run while the request is live. Starts the anonymous owner key the
   * way PrivateTempStore::set() would.
   */
  public function bindOwner(): void {
    if ($this->owner !== NULL) {
      return;
    }
    if ($this->currentUser->isAuthenticated()) {
      $this->owner = (string) $this->currentUser->id();
      return;
    }
    $session = $this->requestStack->getSession();
    if (!$session->has(self::OWNER_SESSION_KEY)) {
      $session->set(self::OWNER_SESSION_KEY, Crypt::randomBytesBase64());
    }
    $this->owner = (string) $session->get(self::OWNER_SESSION_KEY);
  }

  /**
   * Loads a thread, or an empty one when none is stored.
   */
  public function load(string $threadId): ThreadState {
    return ThreadState::fromArray($this->get($this->key($threadId)));
  }

  /**
   * Saves a thread.
   */
  public function save(string $threadId, ThreadState $state): void {
    $this->set($this->key($threadId), $state->toArray());
  }

  /**
   * Deletes a thread.
   */
  public function delete(string $threadId): void {
    if ($this->owner !== NULL) {
      $this->keyValue()->delete($this->owner . ':' . $this->key($threadId));
      return;
    }
    $this->store()->delete($this->key($threadId));
  }

  /**
   * Returns the owner's current thread id, if one was recorded.
   */
  public function currentThreadId(): ?string {
    $id = $this->get(self::CURRENT_KEY);
    return is_string($id) && $id !== '' ? $id : NULL;
  }

  /**
   * Records the owner's current thread id.
   */
  public function setCurrentThreadId(string $threadId): void {
    $this->set(self::CURRENT_KEY, $threadId);
  }

  private function get(string $key): mixed {
    if ($this->owner === NULL) {
      return $this->store()->get($key);
    }
    $object = $this->keyValue()->get($this->owner . ':' . $key);
    return is_object($object) && (string) ($object->owner ?? '') === $this->owner ? $object->data : NULL;
  }

  private function set(string $key, mixed $value): void {
    if ($this->owner === NULL) {
      $this->store()->set($key, $value);
      return;
    }
    $this->keyValue()->setWithExpire($this->owner . ':' . $key, (object) [
      'owner' => $this->owner,
      'data' => $value,
      'updated' => time(),
    ], $this->expire);
  }

  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get(self::COLLECTION);
  }

  private function keyValue() {
    return $this->keyValueFactory->get('tempstore.private.' . self::COLLECTION);
  }

  private function key(string $threadId): string {
    return 'thread:' . $threadId;
  }

}
