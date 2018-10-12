<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Container\NewsContainerInterface;
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
class ScrapNewsQueueBase extends QueueWorkerBase implements
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

    $container = new NewsContainer();
    $scrapper->newsScrap($url, $container);

    // Create node from the array.
    $this->createContent($container, $municipality);
  }

  /**
   * Create news node based on container object.
   */
  protected function createContent(NewsContainerInterface $container, int $municipality) {
    // Create node object from the $content array.
    $node = Node::create([
      'type'  => 'ding_news',
      'title' => $container->getTitle(),
      'field_ding_news_body'  => [
        'value'  => $container->getBody(),
        'format' => 'full_html',
      ],
      'field_ding_news_lead' => [
        'value' => $container->getLead(),
      ],
      'field_municipality' => [
        'target_id' => $municipality,
      ],
      'field_ding_news_list_image' => [
        'target_id' => $this->prepareImage($container->getListImage()),
        'alt' => $container->getTitle(),
        'title' => $container->getTitle(),
      ],
    ]);

    $category = $this->prepareNewsCategory($container);
    if (!empty($category)) {
      $node->set('field_ding_news_category', $category);
    }

    $tags = $this->prepareNewsTags($container);
    if (!empty($tags)) {
      $node->set('field_ding_news_tags', $tags);
    }

    $target = $this->prepareNewsTarget($container);
    if (!empty($target)) {
      $node->set('field_ding_news_target', $target);
    }

    $node->save();
  }

  /**
   * Get image id from the container object.
   */
  private function prepareImage(string $url) {
    $id = '';
    $file = system_retrieve_file($url, NULL, TRUE, FILE_EXISTS_REPLACE);
    if (!$file) {
      return $id;
    }

    $img = \Drupal::service('file_system')->realpath($file->getFileUri());
    $type = mime_content_type($img);
    $ext = FALSE;
    if ($type) {
      $extensions = explode('/', $type);
      $ext = $extensions[1];
    }
    if (!empty($ext)) {
      $uri = "{$file->getFileUri()}.{$ext}";
      $image = file_copy($file, $uri, FILE_EXISTS_REPLACE);

      $id = $image->id();
    }

    return $id;
  }

  /**
   * Prepare news category taxonomy terms.
   */
  private function prepareNewsCategory(NewsContainerInterface $container) {
    $category = $container->getCategory();
    if (empty($category)) {
      return '';
    }
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', "news_category");
    $query->condition('name', $container->getCategory());
    $tids = $query->execute();
    if (empty($tids)) {
      $category = Term::create([
        'vid' => 'news_category',
        'name' => $container->getCategory(),
      ])->save();
    }
    else {
      $category = reset($tids);
    }

    return $category;
  }

  /**
   * Get tags ids in taxonomy.
   */
  private function prepareNewsTags(NewsContainerInterface $container) {
    $tags = $container->getTags();
    $termTags = [];

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
   * Prepare target taxonomy term.
   */
  private function prepareNewsTarget(NewsContainerInterface $container) {
    $terms = $container->getTarget();
    $termTags = [];

    foreach ($terms as $term) {
      $query = \Drupal::entityQuery('taxonomy_term');
      $query->condition('vid', "news_target");
      $query->condition('name', $term);
      $tids = $query->execute();

      if (empty($tids)) {
        $termTag = Term::create([
          'vid' => 'news_target',
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
