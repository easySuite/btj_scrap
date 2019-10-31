<?php

namespace Drupal\btj_scrapper\Scraping;

use BTJ\Scrapper\Service\ScrapperService;

/**
 * Interface ServiceFactoryInterface.
 */
interface ServiceRepositoryInterface {

  /**
   * Store a service instance.
   *
   * @param \BTJ\Scrapper\Service\ScrapperService $service
   *   Service object.
   */
  public function addService(ScrapperService $service): void;

  /**
   * Gets the registered service instance, if any.
   *
   * @param string $identifier
   *   Service identifier.
   * @param int $gid
   *   Group id.
   *
   * @return \BTJ\Scrapper\Service\ScrapperService
   *   Service instance.
   */
  public function getService(string $identifier, int $gid = NULL): ScrapperService;
}

