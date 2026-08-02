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
   *
   * The theme's result cards are attached here rather than from
   * hook_block_view_alter(). That hook fires for blocks rendered through a
   * block entity, and this page builds the plugin directly, so it would never
   * run. Attaching on the one route that has a search UI keeps the renderer
   * and its stylesheet off every other page.
   */
  public function search(): array {
    $build = $this->blockManager->createInstance('scolta_search', [])->build();
    $build['#attached']['library'][] = 'complianceiq/scolta-rich-results';
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
