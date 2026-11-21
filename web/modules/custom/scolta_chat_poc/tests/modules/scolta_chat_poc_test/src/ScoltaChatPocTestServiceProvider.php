<?php

declare(strict_types=1);

namespace Drupal\scolta_chat_poc_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces scolta.ai_service with FakeAiService.
 */
final class ScoltaChatPocTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('scolta.ai_service')) {
      $container->getDefinition('scolta.ai_service')
        ->setClass(FakeAiService::class)
        ->setFactory(NULL)
        ->setArguments([new Reference('state')]);
    }
  }

}
