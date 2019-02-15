<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use \Drupal\file\Entity\File;

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
   * Get list image id to be saved on node creation.
   */
  public function prepareImage(string $url) {
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
   * Get node from btj_scrapper_nodes table by its hash from scrapped entity.
   */
  public function getNodebyHash($hash) {
    $nid = \Drupal::database()->select('btj_scrapper_nodes', 'n')
      ->fields('n', ['entity_id'])
      ->condition('n.item_hash', $hash)
      ->execute()
      ->fetchField();

    return $nid;
  }

  /**
   * Save relations between drupal node and scrapped item.
   */
  public function setNodeRelations($nid, $bundle, $hash) {
    $connection = \Drupal::database();
    $connection->merge('btj_scrapper_nodes')
      ->keys(['item_hash' => $hash])
      ->fields([
        'entity_id' => $nid,
        'bundle' => $bundle,
      ])
      ->execute();
  }

}
