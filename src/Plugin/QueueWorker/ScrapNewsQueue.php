<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

/**
 * Create node object from the imported scrapped content.
 *
 * @QueueWorker(
 *   id = "btj_scrapper_ding_news",
 *   title = @Translation("Scrap news."),
 *   cron = {"time" = 60}
 * )
 */
class ScrapNewsQueue extends ScrapNewsQueueBase {}
