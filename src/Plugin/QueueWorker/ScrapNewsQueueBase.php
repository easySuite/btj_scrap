<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Transport\GouteHttpTransport;
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
    $transport = new GouteHttpTransport();

    $type = $item->type;
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
    $scrapper->newsScrap($item->link, $container);
    sleep(5);

    if ($item->entity_id) {
      $node = Node::load($item->entity_id);
      $this->nodePrepare($container, $node);
    }
    else {
      $node = Node::create(['type' => 'ding_news']);
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
