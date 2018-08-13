<?php

namespace Drupal\btj_scrap\Controller;

use Drupal\Core\Controller\ControllerBase;
use \Drupal\node\Entity\Node;
use \Drupal\file\Entity\File;

use BTJ\Scrapper\Transport\GouteHttpTransport;
use BTJ\Scrapper\Service\CSLibraryService;
use BTJ\Scrapper\Service\AxiellLibraryService;
use BTJ\Scrapper\Crawler;
use BTJ\Scrapper\Container\Container;
use BTJ\Scrapper\Container\EventContainerInterface;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\NewsContainerInterface;
use BTJ\Scrapper\Container\NewsContainer;
use BTJ\Scrapper\Container\LibraryContainerInterface;
use BTJ\Scrapper\Container\LibraryContainer;

class ScrapController extends ControllerBase {
  protected $url;
  protected $scrapper;

  public function __construct() {
    $this->url = 'https://bibliotek.ekero.se';

    // Events group url
    //     $url = 'https://bibliotek.ekero.se/calendar/html?fDateMin=2018-01-01';
    //    $url = 'https://bibliotek.ekero.se/sv/event/fotografera-med-ett-proffs/496b1857-7ac4-4286-8a44-74ce6ab8b2c7';
    // News group url
    //    $url = 'https://bibliotek.ekero.se/search?fType=news#content-results';
    //    $url = 'https://bibliotek.ekero.se/sv/news/usha-balasundaram-fick-barnens-eget-trolldiplom-2017';

    //    $url = 'https://bibliotek.ekero.se/58ca5c6c90cba22d5042c344-sv?type=library-page';


    $transport = new GouteHttpTransport();
    $this->scrapper = new CSLibraryService($transport);
  }

  public function content() {
    $container = new EventContainer();
//    $container = new NewsContainer();
//    $container = new LibraryContainer();

    $crawler = new Crawler($this->scrapper);

/*    $links = $crawler->getCTLinks($this->url . '/calendar/html?fDateMin=2018-08-01', $container);
    foreach ($links as $link) {
      $crawler->getNode($this->url . $link, $container);
//      var_dump($link, $container);
      $this->createNode($container);
    }*/

    $link = $this->url . '/sv/event/fotografera-med-ett-proffs/496b1857-7ac4-4286-8a44-74ce6ab8b2c7';
    $crawler->getNode($link, $container);
    $this->createNode($container);

    // Save event goes here.
    return [
      '#type' => 'markup',
      '#markup' => $this->t($container->getBody()),
    ];
  }

  private function createNode(Container $container) {
    if ($container instanceof EventContainerInterface) {
      $this->createEvent($container);
    }
    elseif ($container instanceof NewsContainerInterface) {
      $this->createNews($container);
    }
    if ($container instanceof LibraryContainerInterface) {
      $this->createLibrary($container);
    }
  }

  private function createEvent(Container $container) {
    $node = Node::create([
      'type' => 'ding_event',
      'title' => $container->getTitle(),
      'field_ding_event_list_image' => [
        'target_id' => $this->prepareEventListImage($container),
      ],
/*      'field_ding_event_title_image' => [
        'target_id' => $this->prepareEventTitleImage($container),
      ],*/
      'field_ding_event_lead' => $container->getLead(),
      'field_ding_event_body' => $container->getBody(),
      'field_ding_event_category' => [
        'target_id' => $this->prepareEventCategory($container),
      ],
      'field_ding_event_tags' => $this->prepareEventTags($container),
      'field_ding_event_price' => $container->getPrice(),
      'field_ding_event_date' => $this->prepareEventDate($container),
    ]);

    $node->save();
  }

  private function createNews(Container $container) {

  }

  private function createLibrary(Container $container) {

  }

  private function prepareEventCategory(Container $container) {
    $query = \Drupal::entityQuery('taxonomy_term');
    $query->condition('vid', "event_category");
    $query->condition('name', $container->getCategory());
    $tids = $query->execute();
    if (empty($tids)) {
      $category = \Drupal\taxonomy\Entity\Term::create([
        'vid' => 'event_category',
        'name' => $container->getCategory(),
      ])->save();
    } else {
      $category = reset($tids);
    }

    return $category;
  }

  private function prepareEventTags(Container $container) {
    $tags = $container->getTags();

    foreach ($tags as $tag) {
      $query = \Drupal::entityQuery('taxonomy_term');
      $query->condition('vid', "tags");
      $query->condition('name', $tag);
      $tids = $query->execute();

      if (empty($tids)) {
        $termTag = \Drupal\taxonomy\Entity\Term::create([
          'vid' => 'tags',
          'name' => $tag,
        ]);

        $termTag->save();
        $termTags[] = $termTag->id();
      } else {
        $termTags[] = reset($tids);
      }
    }

    return $termTags;
  }

  private function prepareEventListImage(Container $container) {
    // Create list image object from remote URL.
    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $container->getListImage()]);
    $listImage = reset($files);

    // if not create a file
    if (!$listImage) {
      $listImage = File::create([
        'uri' => $container->getListImage(),
      ]);
      $listImage->save();
    }

    return $listImage->id();
  }

  private function prepareEventTitleImage(Container $container) {
    // Create title image object from remote URL.
    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $container->getTitleImage()]);
    $titleImage = reset($files);

    // if not create a file
    if (!$titleImage) {
      $titleImage = File::create([
        'uri' => $container->getTitleImage(),
      ]);
      $titleImage->save();
    }

    return $titleImage->id();
  }

  private function prepareEventDate(Container $container) {
    $mapping = ['januari' => '01', 'februari' => '02', 'mars' => '03', 'april' => '04', 'maj' => '05', 'juni' => '06', 'juli' => '07', 'augusti' => '08', 'september' => '09', 'oktober' => '10', 'november' => '11','december' => '12'];
    $year = date("Y");
    $month = $mapping[$container->getMonth()];
    $date = $container->getDate();
    $hours = explode(' – ', $container->getTime());

    $startEvent = \DateTime::createFromFormat('Y-m-d\TH:i:s', "$year-$month-$date" . "T" . "$hours[0]:00");
    $endEvent = \DateTime::createFromFormat('Y-m-d\TH:i:s', "$year-$month-$date" . "T" . "$hours[1]:00");

    return [$startEvent, $endEvent];
  }

}
