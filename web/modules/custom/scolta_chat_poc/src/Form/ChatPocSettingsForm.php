<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the Scolta grounded chat.
 */
final class ChatPocSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'scolta_chat_poc_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['scolta_chat_poc.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('scolta_chat_poc.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat'),
      '#description' => $this->t('Shows the chatbot blocks that use the Scolta grounded processor. When off they are hidden and their API refuses turns.'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['followups_open_chat'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Follow ups on search open the chat'),
      '#description' => $this->t('A question typed into the follow up box under a search summary opens the chatbot and continues there, seeded with the search and its summary.'),
      '#default_value' => $config->get('followups_open_chat'),
    ];

    $form['context'] = [
      '#type' => 'details',
      '#title' => $this->t('Context bounds'),
      '#open' => TRUE,
    ];
    $form['context']['window_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Window size'),
      '#description' => $this->t('Messages sent verbatim on each turn. Older ones are folded into the running summary.'),
      '#min' => 2,
      '#max' => 40,
      '#default_value' => $config->get('window_messages'),
    ];
    $form['context']['summary_words'] = [
      '#type' => 'number',
      '#title' => $this->t('Summary length'),
      '#description' => $this->t('Maximum words in the running summary of older turns.'),
      '#min' => 20,
      '#max' => 1000,
      '#default_value' => $config->get('summary_words'),
    ];
    $form['context']['context_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Search context budget'),
      '#description' => $this->t('Characters of search excerpts sent with one turn.'),
      '#min' => 500,
      '#max' => 50000,
      '#default_value' => $config->get('context_chars'),
    ];
    $form['context']['history_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Conversation backstop'),
      '#description' => $this->t('Characters of conversation sent to the model before the oldest exchanges are dropped.'),
      '#min' => 2000,
      '#max' => 200000,
      '#default_value' => $config->get('history_chars'),
    ];
    $form['context']['answer_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Answer tokens'),
      '#min' => 100,
      '#max' => 8000,
      '#default_value' => $config->get('answer_tokens'),
    ];
    $form['context']['turn_ceiling'] = [
      '#type' => 'number',
      '#title' => $this->t('Turn ceiling'),
      '#description' => $this->t('A runaway guard, not a follow up cap: after this many turns the chat suggests a fresh conversation.'),
      '#min' => 1,
      '#max' => 1000,
      '#default_value' => $config->get('turn_ceiling'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('scolta_chat_poc.settings');
    foreach (['enabled', 'followups_open_chat'] as $key) {
      $config->set($key, (bool) $form_state->getValue($key));
    }
    foreach (['window_messages', 'summary_words', 'context_chars', 'history_chars', 'answer_tokens', 'turn_ceiling'] as $key) {
      $config->set($key, (int) $form_state->getValue($key));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
