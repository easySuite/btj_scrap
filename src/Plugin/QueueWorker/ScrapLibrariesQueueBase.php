<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\opening_hours\OpeningHours\Instance;
use Drupal\opening_hours\Services\InstanceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Container\LibraryContainer;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapLibrariesQueueBase extends ScrapQueueWorkerBase {

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
    $url = $item->link;
    $type = $item->type;
    $municipality = $item->municipality;

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

    $nid = $this->getNodebyHash($container->getHash());
    if ($nid) {
      $node = Node::load($nid);
      $this->nodePrepare($container, $node);
    }
    else {
      $node = Node::create(['type' => 'ding_library']);
      $this->nodePrepare($container, $node);
      $node->field_municipality->target_id = $municipality;
    }
    $node->save();

    // Assign opening hours.
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

    $this->setNodeRelations($node->id(), 'ding_library', $container->getHash(), $url);
  }

  function nodePrepare($container, &$node) {
    $node->setTitle($container->getTitle());


    $node->field_ding_library_body->value = $container->getBody();
    $node->field_ding_library_body->format = 'full_html';

    $node->field_ding_library_title_image->target_id = $this->prepareImage($container->getTitleImage());
    $node->field_ding_library_title_image->alt = $container->getTitle();
    $node->field_ding_library_title_image->title = $container->getTitle();

    $node->field_ding_library_addresse->country_code = 'SE';
    $node->field_ding_library_addresse->locality = $container->getCity();
    $node->field_ding_library_addresse->address_line1 = $container->getStreet();
    $node->field_ding_library_addresse->postal_code = $container->getZip();
    $node->set('field_ding_library_mail', $container->getEmail());
    $node->set('field_ding_library_phone_number', $container->getPhone());
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
          'notice' => null,
          'category_tid' => null,
          'customised' => 0,
        ]);
        $instances[] = $instanceObject;
      }

      $today->modify('next day');
    }

    return $instances;
  }

}
