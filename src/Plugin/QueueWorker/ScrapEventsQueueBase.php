<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\EventContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use \Drupal\node\Entity\Node;
use \Drupal\file\Entity\File;
use \Drupal\taxonomy\Entity\Term;
use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapEventsQueueBase extends QueueWorkerBase implements
    ContainerFactoryPluginInterface {

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
    // Get the content array.
    $url = $item->data['link'];
    $type = $item->data['type'];

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

    // Create node from the array.
    $this->createContent($container);
  }

  /**
   * {@inheritdoc}
   */
  public function createContent(EventContainerInterface $container) {
    $node = Node::create([
      'type' => 'ding_event',
      'title' => $container->getTitle(),
      'field_ding_event_list_image' => [
        'target_id' => $this->prepareImage($container->getListImage()),
        'alt' => $container->getTitle(),
        'title' => $container->getTitle(),
      ],
      'field_ding_event_lead' => $container->getLead(),
      'field_ding_event_body' => [
        'value'  => $container->getBody(),
        'format' => 'full_html',
      ],
      'field_ding_event_category' => [
        'target_id' => $this->prepareEventCategory($container),
      ],
      'field_ding_event_tags' => $this->prepareEventTags($container),
      'field_ding_event_price' => $container->getPrice(),
      'field_ding_event_date' => $this->prepareEventDate($container),

    ]);

    $target = $this->prepareEventTarget($container);
    if (!empty($target)) {
      $node->set('field_ding_event_target', $target);
    }
    $node->save();
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
   * Get list image id to be saved on node creation.
   */
  private function prepareImage(string $url) {
    $file = system_retrieve_file($url, NULL, TRUE, FILE_EXISTS_REPLACE);
    $img = \Drupal::service('file_system')->realpath($file->getFileUri());
    $type = mime_content_type($img);
    $ext = FALSE;
    if ($type) {
      $extensions = explode('/', $type);
      $ext = $extensions[1];
    }
    if ($ext) {
      $uri = "{$file->getFileUri()}.{$ext}";
      $image = file_copy($file, $uri, FILE_EXISTS_REPLACE);
    }

    return $image->id();
  }

  /**
   * Get title image id to be saved on node creation.
   */
  private function prepareEventTitleImage(EventContainerInterface $container) {
    // Create title image object from remote URL.
    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $container->getTitleImage()]);
    $titleImage = reset($files);

    if (!$titleImage) {
      $titleImage = File::create([
        'uri' => $container->getTitleImage(),
      ]);
      $titleImage->save();
    }

    return $titleImage->id();
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
