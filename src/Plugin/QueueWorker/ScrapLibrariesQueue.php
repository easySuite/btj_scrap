<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

/**
 * Create node object from the imported scrapped content.
 *
 * @QueueWorker(
 *   id = "btj_scrapper_ding_library",
 *   title = @Translation("Scrap libraries."),
 *   cron = {"time" = 60}
 * )
 */
class ScrapLibrariesQueue extends ScrapLibrariesQueueBase {}
