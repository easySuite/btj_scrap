<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class ScrapQueueWorkerBase extends QueueWorkerBase implements
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
   * Prepare all fields of the node.
   */
  abstract protected function nodePrepare($container, &$node);

  /**
   * Get bundle category term.
   */
  protected function prepareCategory($category, $bundle) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', $bundle . "_category");
    $query->condition('name', $category);
    $tids = $query->execute();
    if (empty($tids)) {
      $term = Term::create([
        'vid' => $bundle . '_category',
        'name' => $category,
      ])->save();
    }
    else {
      $term = reset($tids);
    }

    return $term;
  }

  /**
   * Get tags ids in taxonomy.
   */
  protected function prepareTags($tags) {
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
  protected function prepareTarget($terms, $bundle) {
    $termTags = [];

    foreach ($terms as $term) {
      $query = \Drupal::entityQuery('taxonomy_term');
      $query->condition('vid', $bundle . "_target");
      $query->condition('name', $term);
      $tids = $query->execute();

      if (empty($tids)) {
        $termTag = Term::create([
          'vid' => $bundle . '_target',
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

  /**
   * Get list image id to be saved on node creation.
   */
  protected function prepareImage(string $url) {
    /** @var \Drupal\file\FileInterface $file */
    $file = system_retrieve_file($url, NULL, FALSE, FILE_EXISTS_REPLACE);
    if (!$file) {
      return NULL;
    }

    $image_info = getimagesize($file);
    // This a'int an image.
    if (!$image_info) {
      return NULL;
    }

    $extension = explode('/', $image_info['mime'])[1];

    $fileEntity = File::create();
    $fileEntity->setFileUri($file);
    $fileEntity->setMimeType($image_info['mime']);
    $fileEntity->setFilename(basename($file));

    /** @var \Drupal\file\FileInterface $managedFile */
    $managedFile = file_copy($fileEntity, $file . '.' . $extension, FILE_EXISTS_REPLACE);
    file_unmanaged_delete($file);

    return $managedFile ? $managedFile->id() : NULL;
  }

  /**
   * Get node from btj_scrapper_nodes table by its url from scrapped entity.
   */
  public function getNodebyURL($url) {
    $nid = \Drupal::database()->select('btj_scrapper_nodes', 'n')
      ->fields('n', ['entity_id'])
      ->condition('n.item_url', $url)
      ->execute()
      ->fetchField();

    return $nid;
  }

}
