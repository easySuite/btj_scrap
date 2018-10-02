<?php

namespace Drupal\btj_scrapper\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Queue\QueueWorkerManager;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Crawler;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Container\LibraryContainer;

define("BTJ_SCRAP_BATCH_SIZE", 1);

class ScrapController extends ControllerBase {

  public function library() {
//    $url = 'https://bibliotek.ekero.se/58ca5c6c90cba22d5042c344-sv?type=library-page';
//    $url = 'https://bibliotek.ekero.se/58ca5bb490cba22d5042c33a-sv?type=library-page';
    $url = 'https://bibliotek.boras.se/58ec9f8c4781720e6c2038ba-sv?type=library-page';
    $container = new LibraryContainer();
    $transport = new GouteHttpTransport();
    $scrapper = new CSLibraryService($transport);
    $scrapper->libraryScrap($url, $container);
    $container->getOpeningHours();
    return [
      '#markup' => $container->getBody(),
    ];
  }

  public function news() {
    $url = 'https://bibliotek.boras.se/sv/news/nominerade-till-augustpriset-2017';
    $container = new NewsContainer();
    $transport = new GouteHttpTransport();
    $scrapper = new CSLibraryService($transport);
    $scrapper->newsScrap($url, $container);

    return [
      '#markup' => $container->getBody(),
    ];
  }

  /**
   * @var QueueFactory
   */
  protected $queueFactory;

  /**
   * @var QueueWorkerManager
   */
  protected $queueManager;

  public function __construct(QueueFactory $queue_factory, QueueWorkerManager $queue_manager) {
    $this->queue_factory = $queue_factory;
    $this->queue_manager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $queue_factory = $container->get('queue');
    $queue_manager = $container->get('plugin.manager.queue_worker');

    return new static($queue_factory, $queue_manager);
  }

  public function prepare($group, $entity) {
    $type = $group->get('field_scrapping_type')->getValue();
    $type = $type[0]['value'];
    $url = $group->get('field_scrapping_url')->getValue();
    $url = $url[0]['uri'];

    $transport = new GouteHttpTransport();
    // Prepare scrapper.
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

    $container = NULL;
    switch ($entity) {
      case 'libraries':
        $container = new LibraryContainer();
        break;
      case 'events':
        $container = new EventContainer();
        break;
      case 'news':
        $container = new NewsContainer();
        break;
    }
    if (!$container) {
      return;
    }

    $crawler = new Crawler($scrapper);
    $links = $crawler->getCTLinks($url, $container);
    foreach ($links as $link) {
      $queue = $this->queue_factory->get("btj_scrap_$entity");

      // Create new queue item
      $item = new \stdClass();
      $item->data = [
        'link' => $url . $link,
        'type' => $type,
        'municipality' => $group->id(),
      ];

      $queue->createItem($item);
    }
  }

  public function scrap(GroupInterface $group, $entity) {
    $this->prepare($group, $entity);

    // Create batch which collects all the specified queue items and process them one after another
    $batch = [
      'title' => $this->t('Scrap and import @entity from <i>@municipality</i>',
      ['@entity' => $entity, '@municipality' => $group->label()]),
      'operations' => [],
      'finished' => 'Drupal\btj_scrapper\Controller\ScrapController::batchFinished',
    ];

    // Get the queue implementation for import_content_from_xml queue
    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get("btj_scrap_$entity");
    $items = $queue->numberOfItems();

    // Count number of the items in this queue, and create enough batch operations
    for($i = 0; $i < ceil($queue->numberOfItems() / BTJ_SCRAP_BATCH_SIZE); $i++) {
      // Create batch operations
      $batch['operations'][] = array('Drupal\btj_scrapper\Controller\ScrapController::import', [$entity]);
    }

    // Adds the batch sets
    batch_set($batch);

    // Process the batch and after redirect to the municipality page.
    return batch_process('/group/' . $group->id());
  }

  public static function import($entity, &$context) {
    $queue_factory = \Drupal::service('queue');
    $queue_manager = \Drupal::service('plugin.manager.queue_worker');

    $queue = $queue_factory->get("btj_scrap_$entity");
    // Get the queue worker
    $queue_worker = $queue_manager->createInstance("btj_scrap_$entity");

    // Get the number of items
    $number_of_queue = ($queue->numberOfItems() < BTJ_SCRAP_BATCH_SIZE) ? $queue->numberOfItems() : BTJ_SCRAP_BATCH_SIZE;
    for ($i = 0; $i < $number_of_queue; $i++) {
      // Get a queued item
      if ($item = $queue->claimItem()) {
        try {
          // Process it
          $queue_worker->processItem($item->data);
          // If everything was correct, delete the processed item from the queue
          $queue->deleteItem($item);
        }
        catch (SuspendQueueException $e) {
          // If there was an Exception trown because of an error
          // Releases the item that the worker could not process.
          // Another worker can come and process it
          $queue->releaseItem($item);
          break;
        }
      }
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addMessage(t('All content has been correctly scrapped.'));
    }
    else {
      $error_operation = reset($operations);
      \Drupal::messenger()->addMessage(t('An error occurred while processing @operation with arguments : @args', array('@operation' => $error_operation[0], '@args' => print_r($error_operation[0], TRUE))));
    }
  }
}
