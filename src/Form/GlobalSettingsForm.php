<?php

namespace Drupal\btj_scrapper\Form;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ServiceSettingsForm.
 *
 * Allows to set MobileSearch service settings.
 */
class GlobalSettingsForm extends FormBase {

  const FORM_ID = 'btj.global_settings_form';

  protected $dateFormatter;

  protected $messenger;

  /**
   * GlobalSettingsForm constructor.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Date formatter service.
   */
  public function __construct(DateFormatterInterface $dateFormatter, MessengerInterface $messenger) {
    $this->dateFormatter = $dateFormatter;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return self::FORM_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('btj_scrapper.settings');
    $last_cron = $config->get('btj_queue_last_cron') ?? 0;

    if (!empty($last_cron)) {
      $last_cron = $this->dateFormatter->format($last_cron, 'medium');
    }

    $form['reset_cron'] = [
      '#type' => 'container',
      '#markup' => '<p>' . $this->t('Use this form to reset the queue on next cron run.') . '</p>',
    ];

    $form['last_cron'] = [
      '#type' => 'container',
      '#markup' => '<p>' . $this->t('Last queue cron: @last_cron', [
        '@last_cron' => $last_cron,
      ]) . '</p>',
    ];

    $form['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('btj_scrapper.settings');
    $config
      ->set('btj_queue_last_cron', 0)
      ->save();

    $this->messenger->addMessage($this->t('Last scraping queue cron run has been reset.'));
  }

}
