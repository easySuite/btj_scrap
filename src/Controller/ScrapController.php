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

/**
 * Implement scrap controller.
 */
class ScrapController extends ControllerBase {

  /**
   * Scrap single event based on the hardcoded link.
   */
  public function scrap() {

    $rows = \Drupal::database()->select('btj_scrapper_relations', 'bsr')
      ->fields('bsr')
      ->condition('bsr.status', 0)
      ->orderBy('weight', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll();

    return [
      '#markup' => 'hoho',
    ];
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

    $links = $crawler->getCTLinks($url, $container);
    $gid = $group->id();
    $uid = $this->getAuthorByMunicipality($gid);
    foreach ($links as $link) {
      $this->updateRelations($link, $bundle, NULL, $uid, $gid, $type);
    }
  }

  /**
   * Update relations between scrapped item and the drupal node.
   */
  public function updateRelations($url, $bundle, $nid = NULL, $uid = NULL, $gid = NULL, $type = '', $status = 0, $weight = 0) {
    $connection = \Drupal::database();
    $connection->merge('btj_scrapper_relations')
      ->keys(['item_url' => $url])
      ->fields([
        'bundle' => $bundle,
        'entity_id' => $nid,
        'uid' => $uid,
        'gid' => $gid,
        'type' => $type,
        'status' => $status,
        'weight' => ($bundle == 'library') ? $weight + 1 : $weight,
      ])
      ->execute();
  }


}
