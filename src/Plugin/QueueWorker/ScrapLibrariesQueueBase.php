<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\btj_scrapper\Controller\ScrapController;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\opening_hours\OpeningHours\Instance;
use Drupal\opening_hours\Services\InstanceManager;
use Drupal\node\Entity\Node;
use BTJ\Scrapper\Container\LibraryContainer;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    /** @var \Drupal\btj_scrapper\Scraping\ServiceRepositoryInterface $serviceRepository */
    $serviceRepository = \Drupal::service('btj_scrapper_service_repository');
    $scrapper = $serviceRepository->getService($item->type, $item->gid);

    $container = new LibraryContainer();
    $scrapper->libraryScrap($item->link, $container);
    sleep(1);

    if (empty($item->entity_id) || NULL === ($node = Node::load($item->entity_id))) {
      $node = Node::create(['type' => 'ding_library']);
      $node->field_municipality->target_id = $item->gid;
    }

    $this->nodePrepare($container, $node);
    $node->setOwnerId($item->uid);
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

    ScrapController::writeRelations(
      $item->link,
      $node->bundle(),
      $node->id(),
      $node->getRevisionAuthor()->id(),
      $item->gid,
      $item->type
    );
  }

  /**
   * {@inheritdoc}
   */
  function nodePrepare($container, NodeInterface &$node) {
    // This, normally, should not happen.
    if ('ding_library' !== $node->bundle()) {
      return;
    }

    $node->setTitle($container->getTitle());

    $node->field_ding_library_body->value = $container->getBody();
    $node->field_ding_library_body->format = 'full_html';

    if (!empty($container->getTitleImage())) {
      $node->field_ding_library_title_image->target_id = $this->prepareImage($container->getTitleImage());
      $node->field_ding_library_title_image->alt = $container->getTitle();
      $node->field_ding_library_title_image->title = $container->getTitle();
    }

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
      [$start, $end] = explode('-', $dayHour);
      [$startHour, $startMinute] = explode(':', $start);
      [$endHour, $endMinute] = explode(':', $end);

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
