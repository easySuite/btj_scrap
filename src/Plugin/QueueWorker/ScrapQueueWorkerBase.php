<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ScrapQueueWorkerBase.
 */
abstract class ScrapQueueWorkerBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * ScrapQueueWorkerBase constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger instance.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('logger.factory')->get('btj_scrapper')
    );
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
    $destination = 'public://';
    $fileName = sha1($url);

    try {
      /** @var \Drupal\file\FileInterface $file */
      $file = system_retrieve_file($url, $destination . $fileName, FALSE, FILE_EXISTS_REPLACE);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('Failed to read image from "@url". Exception thrown with message "@message".', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

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
    $fileEntity->setFilename($fileName . '.' . $image_info['mime']);

    /** @var \Drupal\file\FileInterface $managedFile */
    $managedFile = file_copy($fileEntity, $file . '.' . $extension, FILE_EXISTS_REPLACE);
    file_unmanaged_delete($file);

    return $managedFile ? $managedFile->id() : NULL;
  }

}
