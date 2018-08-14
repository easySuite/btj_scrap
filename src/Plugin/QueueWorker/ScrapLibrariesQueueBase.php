<?php

/**
 * @file
 * Contains Drupal\btj_scrap\Plugin\QueueWorker\ImportContentFromXMLQueueBase
 */

namespace Drupal\btj_scrap\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Container\LibraryContainerInterface;
use BTJ\Scrapper\Container\LibraryContainer;
/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapLibrariesQueueBase extends QueueWorkerBase implements
  ContainerFactoryPluginInterface {

  protected $container = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct() {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static();
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    // Get the content array
    $url = $item->data['link'];
    $type = $item->data['type'];

    $transport = new GouteHttpTransport();

    $scrapper = NULL;
    if ($type == 'cslibrary') {
      $scrapper = new CSLibraryService($transport);
    }
    elseif ($type == 'axiel') {
      $scrapper = new AxiellLibraryService($transport);
    }
    if (!$scrapper) {
      return;
    }

    $container = new LibraryContainer();
    $scrapper->libraryScrap($url, $container);

    // Create node from the array
    $this->createContent($container);
  }

  /**
   * @param \BTJ\Scrapper\Container\LibraryContainerInterface $container
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createContent(LibraryContainerInterface $container) {
    // Create node object from the $content array
    $node = Node::create([
      'type'  => 'ding_library',
      'title' => $container->getTitle(),
      'field_ding_library_body'  => [
        'value' => $container->getBody(),
        'format' => 'basic_html',
      ],
    ]);
    $node->save();
  }
}
