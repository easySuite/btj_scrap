<?php

/**
 * @file
 * Contains Drupal\btj_scrapper\Plugin\QueueWorker\ImportContentFromXMLQueueBase
 */

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
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
    $municipality = $item->data['municipality'];

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
    $this->createContent($container, $municipality);
  }

  /**
   * @param \BTJ\Scrapper\Container\LibraryContainerInterface $container
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createContent(LibraryContainerInterface $container, int $municipality) {
    // Create node object from the $content array
    $node = Node::create([
      'type'  => 'ding_library',
      'title' => $container->getTitle(),
      'field_municipality' => [
        'target_id' => $municipality,
      ],
      'field_ding_library_title_image' => [
        'target_id' => $this->prepareImage($container->getTitleImage()),
      ],
      'field_ding_library_body'  => [
        'value' => $container->getBody(),
        'format' => 'basic_html',
      ],
      'field_ding_library_addresse' => [
        'country_code' => 'SE',
        'locality' => $container->getCity(),
        'address_line1' => $container->getStreet(),
        'postal_code' => $container->getZip(),
      ],
      'field_ding_library_mail' => $container->getEmail(),
      'field_ding_library_phone_number' => $container->getPhone(),
      'field_ding_library_opening_hours' => $this->prepareHours($container->getOpeningHours()),
    ]);

    $node->save();
  }

  private function prepareImage(string $url) {
    // Create list image object from remote URL.
    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $url]);
    $image = reset($files);

    if (!$image) {
      $image = File::create([
        'uri' => $url,
      ]);
      $image->save();
    }

    return $image->id();
  }

  private function prepareHours($hours) {
    array_walk($hours, function (&$day, $key) {
      list($start, $end) = explode('-', $day);
      $start = implode('', explode(':', $start));
      $end = implode('', explode(':', $end));
      $day = ((int) $start) ? ['day' => $key, 'starthours' => $start, 'endhours' => $end] : [];
    });

    return $hours;
  }
}
