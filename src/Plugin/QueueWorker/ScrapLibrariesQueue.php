<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

/**
 * Create node object from the imported scrapped content.
 *
 * @QueueWorker(
 *   id = "btj_scrap_libraries",
 *   title = @Translation("Scrap libraries."),
 *   cron = {"time" = 60}
 * )
 */
class ScrapLibrariesQueue extends ScrapLibrariesQueueBase {}
