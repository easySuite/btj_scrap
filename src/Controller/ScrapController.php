<?php

namespace Drupal\btj_scrapper\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Crawler;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Container\LibraryContainer;


use Drupal\btj_scrapper\Plugin\QueueWorker\ScrapEventsQueueBase;
/**
 * Implement scrap controller.
 */
class ScrapController extends ControllerBase {

  /**
   * Scrap single library based on the hardcoded link.
   */
  public function library() {
    $url = 'https://bibliotek.boras.se/58ec9f8c4781720e6c2038ba-sv?type=library-page';
    $container = new LibraryContainer();
    $transport = new GouteHttpTransport();
    $scrapper = new CSLibraryService($transport);
    $scrapper->libraryScrap($url, $container);

    $path = $container->getHash();

    return [
      '#markup' => $path,
    ];
  }

  /**
   * Scrap single news based on the hardcoded link.
   */
  public function news() {
    $url = 'https://bibliotek.boras.se/sv/news/anna-clara-tidholm';
    $container = new NewsContainer();
    $transport = new GouteHttpTransport();
    $scrapper = new CSLibraryService($transport);
    $scrapper->newsScrap($url, $container);

    return [
      '#markup' => $container->getHash(),
    ];
  }

  /**
   * Scrap single event based on the hardcoded link.
   */
  public function event() {
    $url = 'https://bibliotek.boras.se/sv/event/l%C3%A4xhj%C3%A4lp-1/64fc0b17-0007-4ba2-bcba-c5887c9cb09f';

    $container = new EventContainer();
    $transport = new GouteHttpTransport();
    $scrapper = new CSLibraryService($transport);
    $scrapper->eventScrap($url, $container);

    $worker = new ScrapEventsQueueBase();
    $date = $worker->prepareEventDate($container);
    return [
      '#markup' => $date,
    ];
  }

  /**
   * Get node from btj_scrapper_nodes table by its URL from scrapped entity.
   */
  public function getNodebyURL($url) {
    $nid = \Drupal::database()->select('btj_scrapper_nodes', 'n')
      ->fields('n', ['entity_id'])
      ->condition('n.item_url', $url)
      ->execute()
      ->fetchField();

    return $nid;
  }

  /**
   * Get related user of the given municipality.
   */
  private function getAuthorByMunicipality($gid) {
    $connection = \Drupal::database();
    $result =$connection->select('user__field_municipality_ref', 'um')
      ->fields('um', ['entity_id'])
      ->condition('um.field_municipality_ref_target_id', $gid)
      ->execute()
      ->fetchField();

    return $result;
  }

  /**
   * Prepare scrap container for content fetch.
   */
  public function prepare(GroupInterface $group, $bundle) {
    $transport = new GouteHttpTransport();
    // Prepare scrapper.
    $scrapper = NULL;
    $type = $group->get('field_scrapping_type')->first()->getString();
    if ($type == 'cslibrary') {
      $scrapper = new CSLibraryService($transport);
    }
    elseif ($type == 'axiel') {
      $scrapper = new AxiellLibraryService($transport);
    }
    if (!$scrapper) {
      return;
    }

    $url = $group->get('field_scrapping_url')->first()->getString();
    $crawler = new Crawler($scrapper);
    $gid = $group->id();

    switch ($bundle) {
      case 'library':
        $container = new LibraryContainer();
        break;
      case 'news':
        $container = new NewsContainer();
        break;
      case 'event':
        $container = new EventContainer();
        break;
    }

    /** @var QueueFactory $queue_factory */
    $queue_factory = \Drupal::service('queue');

    $links = $crawler->getCTLinks($url, $container);
    foreach ($links as $link) {
      $item = new \stdClass();
      $item->municipality = $gid;
      $item->type = $type;
      $item->bundle = $bundle;
      $item->link = $url . $link;
      $item->uid = $this->getAuthorByMunicipality($group->id());

      /** @var QueueInterface $queue */
      $queue = $queue_factory->get('btj_scrap_' . $bundle);
      if (empty($this->getNodebyURL($url))) {
        $queue->createItem($item);
      }
    }
  }

}
