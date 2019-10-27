<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Service\ConfigurableServiceInterface;
use Drupal\btj_scrapper\Controller\ScrapController;
use Drupal\btj_scrapper\Form\GroupCrawlerSettingsForm;
use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\EventContainerInterface;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapEventsQueueBase extends ScrapQueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    /** @var \Drupal\btj_scrapper\Scraping\ServiceRepositoryInterface $serviceRepository */
    $serviceRepository = \Drupal::service('btj_scrapper_service_repository');
    $scrapper = $serviceRepository->getService($item->type, $item->gid);

    $container = new EventContainer();
    $scrapper->eventScrap($item->link, $container);
    sleep(5);

    if ($item->entity_id) {
      $node = Node::load($item->entity_id);
      $this->nodePrepare($container, $node);
    }
    else {
      $node = Node::create(['type' => 'ding_event']);
      $this->nodePrepare($container, $node);
      $node->field_municipality->target_id = $item->gid;
    }
    $node->setOwnerId($item->uid);
    $node->save();

    $controller = new ScrapController();
    $controller->updateRelations($item->link, $item->bundle, $node->id(), $item->uid, $item->gid, $item->type, 0);
  }

  /**
   * {@inheritdoc}
   */
  function nodePrepare($container, &$node) {
    $node->setTitle($container->getTitle());

    $node->set('field_ding_event_lead', $this->plainText($container->getLead()));

    $node->field_ding_event_body->value = $container->getBody();
    $node->field_ding_event_body->format = 'full_html';

    $node->field_ding_event_list_image->target_id = $this->prepareImage($container->getListImage());
    $node->field_ding_event_list_image->alt = $container->getTitle();
    $node->field_ding_event_list_image->title = $container->getTitle();
    $node->set('field_ding_event_price', $container->getPrice());
    $node->set('field_ding_event_date', $this->prepareEventDate($container));

    $category = $container->getCategory();
    if (!empty($category)) {
      $node->field_ding_event_category->target_id = $this->prepareCategory($category, 'event');
    }

    $tags = $container->getTags();
    $tags = array_filter($tags);
    if (!empty($tags)) {
      $node->set('field_ding_event_tags', $this->prepareTags($tags));
    }

    $terms = $container->getTarget();
    $terms = array_filter($terms);
    if (!empty($terms)) {
      $target = $this->prepareTarget($terms, 'event');

      if (!empty($target)) {
        $node->set('field_ding_event_target', $target);
      }
    }
  }

  /**
   * TODO:
   *
   * @param $string
   *
   * @return string
   */
  public function plainText($string): string {
    return trim(strip_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", $string)));
  }

  /**
   * Prepare date field to be saved on node creation.
   */
  public function prepareEventDate(EventContainerInterface $container) {
    if (empty($container->getMonth())) {
      return [];
    }

    $mapping = [
      'januari' => '01',
      'februari' => '02',
      'mars' => '03',
      'april' => '04',
      'maj' => '05',
      'juni' => '06',
      'juli' => '07',
      'augusti' => '08',
      'september' => '09',
      'oktober' => '10',
      'november' => '11',
      'december' => '12',
    ];
    $year = date("Y");
    $month = $mapping[$container->getMonth()];
    $date = $container->getDate();

    $hours = [];
    preg_match('/(\d{2}\.\d{2})(.*(\d{2}\.\d{2}))?/', $container->getTime(), $hours);

    $start = "{$year}-{$month}-{$date}T" . str_replace('.', ':', $hours[1] ?? '00.00') . ':00';
    $end = "{$year}-{$month}-{$date}T" . str_replace('.', ':', $hours[3] ?? '00.00') . ':00';

    return ['value' => $start, 'end_value' => $end];
  }

}
