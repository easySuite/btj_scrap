<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\EventContainerInterface;
use Drupal\node\Entity\Node;
use \Drupal\taxonomy\Entity\Term;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapEventsQueueBase extends ScrapQueueWorkerBase {

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

    $container = new EventContainer();
    $scrapper->eventScrap($url, $container);

    $nid = $this->getNodebyHash($container->getHash());
    if ($nid) {
      $node = Node::load($nid);
      $this->nodePrepare($container, $node);
    }
    else {
      $node = Node::create(['type' => 'ding_event']);
      $this->nodePrepare($container, $node);
      $node->field_municipality->target_id = $municipality;
    }
    $node->save();

    $this->setNodeRelations($node->id(), 'ding_event', $container->getHash());
  }

  /**
   * {@inheritdoc}
   */
  function nodePrepare($container, &$node) {
    $node->setTitle($container->getTitle());

    $node->set('field_ding_event_lead', $container->getLead());

    $node->field_ding_event_body->value = $container->getBody();
    $node->field_ding_event_body->format = 'full_html';

    $node->field_ding_event_list_image->target_id = $this->prepareImage($container->getListImage());
    $node->field_ding_event_list_image->alt = $container->getTitle();
    $node->field_ding_event_list_image->title = $container->getTitle();

    $node->field_ding_event_category->target_id = $this->prepareEventCategory($container);
    $node->set('field_ding_event_tags', $this->prepareEventTags($container));
    $node->set('field_ding_event_price', $container->getPrice());
    $node->set('field_ding_event_date', $this->prepareEventDate($container));

    $target = $this->prepareEventTarget($container);
    if (!empty($target)) {
      $node->set('field_ding_event_target', $target);
    }
  }

  /**
   * Get event category term.
   */
  private function prepareEventCategory(EventContainerInterface $container) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', "event_category");
    $query->condition('name', $container->getCategory());
    $tids = $query->execute();
    if (empty($tids)) {
      $category = Term::create([
        'vid' => 'event_category',
        'name' => $container->getCategory(),
      ])->save();
    }
    else {
      $category = reset($tids);
    }

    return $category;
  }

  /**
   * Get tags ids to be saved on node creation.
   */
  private function prepareEventTags(EventContainerInterface $container) {
    $tags = $container->getTags();

    foreach ($tags as $tag) {
      $query = \Drupal::entityQuery('taxonomy_term');
      $query->condition('vid', "tags");
      $query->condition('name', $tag);
      $tids = $query->execute();

      if (empty($tids)) {
        $termTag = Term::create([
          'vid' => 'tags',
          'name' => $tag,
        ]);

        $termTag->save();
        $termTags[] = $termTag->id();
      }
      else {
        $termTags[] = reset($tids);
      }
    }

    return $termTags;
  }

  /**
   * Prepare dave field to be saved on node creation.
   */
  private function prepareEventDate(EventContainerInterface $container) {
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
    $hours = explode(' – ', $container->getTime());

    if (empty($hours[0])) {
      $hours[0] = '00';
    }
    $start = "{$year}-{$month}-{$date}T{$hours[0]}:00";

    if (empty($hours[1])) {
      $hours[1] = '00';
    }
    $end = "{$year}-{$month}-{$date}T{$hours[1]}:00";

    return ['value' => $start, 'end_value' => $end];
  }

  /**
   * Prepare target taxonomy term.
   */
  private function prepareEventTarget(EventContainerInterface $container) {
    $terms = $container->getTarget();
    $termTags = [];

    foreach ($terms as $term) {
      $query = \Drupal::entityQuery('taxonomy_term');
      $query->condition('vid', "event_target");
      $query->condition('name', $term);
      $tids = $query->execute();

      if (empty($tids)) {
        $termTag = Term::create([
          'vid' => 'event_target',
          'name' => $term,
        ]);

        $termTag->save();
        $termTags[] = $termTag->id();
      }
      else {
        $termTags[] = reset($tids);
      }
    }

    return $termTags;
  }

}
