<?php

namespace Drupal\btj_scrapper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use BTJ\Scrapper\Crawler;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Container\LibraryContainer;

/**
 * Implement scrap controller.
 *
 * TODO: This one is all but static methods, maybe rework as service.
 */
class ScrapController extends ControllerBase {

  /**
   * Get related user of the given municipality.
   */
  public static function getAuthorByMunicipality($gid) {
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
  public static function prepare(GroupInterface $group, $bundle) {
    /** @var \Drupal\btj_scrapper\Scraping\ServiceRepositoryInterface $serviceRepository */
    $serviceRepository = \Drupal::service('btj_scrapper_service_repository');

    if (!$group->get('field_scrapping_type')->count()) {
      return;
    }

    $type = $group->get('field_scrapping_type')->first()->getString();
    $scrapper = $serviceRepository->getService($type, $group->id());

    $url = $group->get('field_scrapping_url')->first()->getString();
    $crawler = new Crawler($scrapper);

    switch ($bundle) {
      case 'ding_library':
        $container = new LibraryContainer();
        break;
      case 'ding_news':
        $container = new NewsContainer();
        break;
      case 'ding_event':
        $container = new EventContainer();
        break;
    }

    $links = $crawler->getCTLinks($url, $container);
    $gid = $group->id();
    $uid = self::getAuthorByMunicipality($gid);
    // TODO: $uid can be empty and it leads to fatal errors.
    foreach ($links as $link) {
      self::writeRelations($link, $bundle, NULL, $uid, $gid, $type);
    }
  }

  /**
   * Write relation between scrapped item and the drupal node.
   */
  public static function writeRelations($url, $bundle, $nid = NULL, $uid = NULL, $gid = NULL, $type = '') {
    $connection = \Drupal::database();

    $fields = [
      'bundle' => $bundle,
      'entity_id' => $nid,
      'uid' => !empty($uid) ? $uid : 1,
      'gid' => $gid,
      'type' => $type,
      'status' => 0,
      'weight' => ('ding_library' === $bundle) ? 0 : 1,
    ];

    $fields = array_filter($fields, function ($v) {
      return !is_null($v);
    });

    $connection->merge('btj_scrapper_relations')
      ->keys([
        'item_url' => $url,
      ])
      ->fields($fields)
      ->execute();
  }

  /**
   * Marks all relations as processed by default.
   */
  public static function resetRelations() {
    $connection = \Drupal::database();

    $connection->update('btj_scrapper_relations')
      ->fields([
        'status' => 1,
      ])
      ->execute();
  }

}
