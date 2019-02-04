<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\opening_hours\OpeningHours\Instance;
use Drupal\opening_hours\Services\InstanceManager;
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
   * Entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Opening hours instances manager.
   *
   * @var \Drupal\opening_hours\Services\InstanceManager
   */
  protected $ohoInstanceManager;

  /**
   * ScrapLibrariesQueueBase constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, InstanceManager $ohoInstanceManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->ohoInstanceManager = $ohoInstanceManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('opening_hours.instance_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    // Get the content array.
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

    // Create node from the array.
    $this->createContent($container, $municipality);
  }

  /**
   * Create library node.
   */
  protected function createContent(LibraryContainerInterface $container, int $municipality) {
    // Create node object from the $content array.
    $node = Node::create([
      'type'  => 'ding_library',
      'title' => $container->getTitle(),
      'field_municipality' => [
        'target_id' => $municipality,
      ],
      'field_ding_library_title_image' => [
        'target_id' => $this->prepareImage($container->getTitleImage()),
        'alt' => $container->getTitle(),
        'title' => $container->getTitle(),
      ],
      'field_ding_library_body'  => [
        'value' => $container->getBody(),
        'format' => 'full_html',
      ],
      'field_ding_library_addresse' => [
        'country_code' => 'SE',
        'locality' => $container->getCity(),
        'address_line1' => $container->getStreet(),
        'postal_code' => $container->getZip(),
      ],
      'field_ding_library_mail' => $container->getEmail(),
      'field_ding_library_phone_number' => $container->getPhone(),
    ]);

    // Save the node, we need it's id further.
    $node->save();

    // Assing opening hours.
    /** @var \Drupal\node\Entity\NodeType $libraryNodeType */
    $libraryNodeType = $this->entityTypeManager
      ->getStorage('node_type')
      ->load('ding_library');
    if ($libraryNodeType->getThirdPartySetting('opening_hours', 'oh_enabled', FALSE)) {
      $openingHoursInstances = $this->prepareHours($container->getOpeningHours());

      /** @var \Drupal\opening_hours\OpeningHours\Instance $openingHoursInstance */
      foreach ($openingHoursInstances as $openingHoursInstance) {
        $openingHoursInstance->setNid($node->id());
        $this->ohoInstanceManager->save($openingHoursInstance);
      }

      // Trigger a node save, so mobilesearch can track changes.
      $node->save();
    }
  }

  /**
   * Prepare image for to be added to field.
   *
   * TODO: This one repeats in every queue class.
   */
  private function prepareImage(string $url) {
    /** @var \Drupal\file\FileInterface $file */
    $file = system_retrieve_file($url, NULL, FALSE, FILE_EXISTS_REPLACE);
    if (!$file) {
      return NULL;
    }

    $image_info = getimagesize($file);
    // This a'int an image.
    if (!$image_info) {
      return NULL;
    }

    $extension = explode('/', $image_info['mime'])[1];

    $fileEntity = File::create();
    $fileEntity->setFileUri($file);
    $fileEntity->setMimeType($image_info['mime']);
    $fileEntity->setFilename(basename($file));

    /** @var \Drupal\file\FileInterface $managedFile */
    $managedFile = file_copy($fileEntity, $file . '.' . $extension, FILE_EXISTS_REPLACE);
    file_unmanaged_delete($file);

    return $managedFile ? $managedFile->id() : NULL;
  }

  /**
   * Prepare opening hours array to be saved in field.
   */
  private function prepareHours(array $dayHours) {
    $today = new \DateTime();
    // Reset day of the week.
    // This will make sure that date is set at the start of the week,
    // regardless of current day.
    $today->setISODate(
      $today->format('o'),
      $today->format('W'),
      1
    );

    $instances = [];

    foreach ($dayHours as $dayHour) {
      list($start, $end) = explode('-', $dayHour);
      list($startHour, $startMinute) = explode(':', $start);
      list($endHour, $endMinute) = explode(':', $end);

      $instanceDate = clone($today);

      if (!empty((int) $start)) {
        $instanceObject = new Instance();
        $instanceObject->build([
          'date' => $today,
          'start_time' => (clone($instanceDate))->setTime($startHour, $startMinute),
          'end_time' => (clone($instanceDate))->setTime($endHour, $endMinute),
          'repeat_rule' => Instance::PROPAGATE_WEEKLY,
          'repeat_end_date' => (clone($instanceDate))->modify('+6 months'),
          'notice' => '',
          'category_tid' => 0,
          'customised' => 0,
        ]);
        $instances[] = $instanceObject;
      }

      $today->modify('next day');
    }

    return $instances;
  }

}
