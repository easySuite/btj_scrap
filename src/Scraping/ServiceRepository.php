<?php

namespace Drupal\btj_scrapper\Scraping;

use BTJ\Scrapper\Exception\BTJException;
use BTJ\Scrapper\Service\ConfigurableServiceInterface;
use BTJ\Scrapper\Service\ScrapperService;
use Drupal\btj_scrapper\Form\GroupCrawlerSettingsForm;
use Drupal\group\Entity\Group;

/**
 * Class ServiceRepository.
 */
class ServiceRepository implements ServiceRepositoryInterface {

  protected $services = [];

  /**
   * {@inheritdoc}
   */
  public function addService(ScrapperService $service): void {
    if (array_key_exists($service->getIdentifier(), $this->services)) {
      throw new BTJException('Service with identifier "' . $service->getIdentifier() . '" already registered.');
    }

    $this->services[$service->getIdentifier()] = $service;
  }

  /**
   * {@inheritdoc}
   */
  public function getService(string $identifier, int $gid = NULL): ScrapperService {
    if (!array_key_exists($identifier, $this->services)) {
      throw new BTJException('Service with identifier "' . $identifier . '" not registered.');
    }

    $scrapper = $this->services[$identifier];

    if ($scrapper instanceof ConfigurableServiceInterface && !empty($gid)) {
      // TODO: Use entity manager.
      $group = Group::load($gid);

      // TODO: Use DI.
      $config = \Drupal::config(GroupCrawlerSettingsForm::CONFIG_ID)
        ->get(GroupCrawlerSettingsForm::buildSettingsKey($group));

      $scrapper->setConfig($config ?? []);
    }

    return $scrapper;
  }
}

