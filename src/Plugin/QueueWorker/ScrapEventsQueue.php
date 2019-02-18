<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

/**
 * Create node object from the imported scrapped content.
 *
 * @QueueWorker(
 *   id = "btj_scrap_event",
 *   title = @Translation("Scrap events."),
 *   cron = {"time" = 60}
 * )
 */
class ScrapEventsQueue extends ScrapEventsQueueBase {}
