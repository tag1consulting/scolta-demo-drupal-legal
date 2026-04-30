<?php

namespace Drupal\complianceiq_scolta\Controller;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Scolta search block as a standalone page.
 */
class SearchController extends ControllerBase {

  public function __construct(
    protected BlockManagerInterface $blockManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.block'),
    );
  }

  /**
   * Search page — delegates entirely to the Scolta search block.
   */
  public function search(): array {
    $build = $this->blockManager->createInstance('scolta_search', [])->build();
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
