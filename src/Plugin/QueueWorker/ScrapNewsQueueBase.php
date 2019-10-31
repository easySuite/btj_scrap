<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Container\NewsContainer;
use Drupal\btj_scrapper\Controller\ScrapController;
use Drupal\node\Entity\Node;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapNewsQueueBase extends ScrapQueueWorkerBase {

  /**
   * {@inheritdoc}
   *
   */
  public function processItem($item) {
    /** @var \Drupal\btj_scrapper\Scraping\ServiceRepositoryInterface $serviceRepository */
    $serviceRepository = \Drupal::service('btj_scrapper_service_repository');
    $scrapper = $serviceRepository->getService($item->type, $item->gid);

    $container = new NewsContainer();
    $scrapper->newsScrap($item->link, $container);
    sleep(1);

    if (empty($item->entity_id) || NULL === ($node = Node::load($item->entity_id))) {
      $node = Node::create(['type' => 'ding_news']);
      $node->field_municipality->target_id = $item->gid;
    }

    $this->nodePrepare($container, $node);
    $node->setOwnerId($item->uid);
    $node->save();

    ScrapController::writeRelations(
      $item->link,
      $node->bundle(),
      $node->id(),
      $node->getRevisionAuthor()->id(),
      $item->gid,
      $item->type
    );
  }

  /**
   * {@inheritdoc}
   */
  function nodePrepare($container, &$node) {
    $node->setTitle($container->getTitle());

    $node->set('field_ding_news_lead', $container->getLead());

    $node->field_ding_news_body->value = $container->getBody();
    $node->field_ding_news_body->format = 'full_html';

    $node->field_ding_news_list_image->target_id = $this->prepareImage($container->getListImage());
    $node->field_ding_news_list_image->alt = $container->getTitle();
    $node->field_ding_news_list_image->title = $container->getTitle();

    $category = $container->getCategory();
    if (!empty($category)) {
      $node->field_ding_news_category->target_id = $this->prepareCategory($category, 'news');
    }

    $tags = $container->getTags();
    $tags = array_filter($tags);
    if (!empty($tags)) {
      $node->set('field_ding_news_tags', $this->prepareTags($tags));
    }

    $terms = $container->getTarget();
    $terms = array_filter($terms);
    if (!empty($terms)) {
      $target = $this->prepareTarget($terms, 'news');

      if (!empty($target)) {
        $node->set('field_ding_news_target', $target);
      }
    }
  }

}
