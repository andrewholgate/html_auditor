<?php

/**
 * @file
 * Contains \Drupal\html_auditor\Controller\AuditorController.
 */

namespace Drupal\html_auditor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;
use Symfony\Component\Finder\Finder;

class AuditorController extends ControllerBase {

  /**
   * Reports limit per page.
   */
  const REPORTS_MAX_LENGTH = 25;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('file_system')
    );
  }

  /**
   * Constructs a AuditorController object.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\File\FileSystem $file_system
   *   The file system service.
   */
  public function __construct(FormBuilderInterface $form_builder, FileSystem $file_system) {
    $this->formBuilder = $form_builder;
    $this->fileSystem = $file_system;
  }

  /**
   * Displays a listing of HTML reports.
   *
   * Ten reports are available per page.
   * Reports fields are sortable.
   *
   * @return array
   *   A render array as expected by drupal_render().
   */
  public function report() {
    $reports = [];
    // Get configs.
    $config = $this->config('html_auditor.settings');
    // Get finder service.
    $reports_find = \Drupal::service('html_auditor.finder');
    // Get reports directory.
    $directory = $this->fileSystem->realpath(sprintf('public://%s', $config->get('sitemap.reports')));
    if (file_prepare_directory($directory)) {
      // Get JSON content from files.
      $reports_find->files()->in($directory)->name('/[a-z0-9]+\-report.json$/');
      foreach ($reports_find as $file) {
        // Get data as an object.
        $contents = (object) Json::decode($file->getContents());
        foreach ($contents as $type => $content) {
          foreach ($content as $file => $data) {
            foreach ($data as $report) {
              switch ($type) {
                // Extract a11y data.
                case 'assessibility':
                  $reports[] = [
                    'file' => $this->fileSystem->basename($file),
                    'type' => $type,
                    'level' => $this->t($report['type']),
                    'message' => $this->t($report['message']),
                  ];
                break;
                // Extract html5 data.
                case 'html5':
                  $reports[] = [
                   'file' => $this->fileSystem->basename($file),
                   'type' => $type,
                   'level' => $this->t($report['type']),
                   'message' => $this->t($report['message']),
                  ];
                break;
                // Extract link data.
                case 'link':
                  $reports[] = [
                   'file' => $this->fileSystem->basename($file),
                   'type' => $type,
                   'level' => $this->t('error'),
                   'message' => $this->t($report['error']),
                  ];
                break;
              }
            }
          }
        }
      }
      // Filter by type.
      if (!empty($_SESSION['html_auditor_reports_filter']['type'])) {
        $reports = array_filter($reports, function($report) {
          $types = $_SESSION['html_auditor_reports_filter']['type'];
          return in_array($report['type'], $types);
        });
      }
      // Filter by error levels.
      if (!empty($_SESSION['html_auditor_reports_filter']['level'])) {
        $reports = array_filter($reports, function($report) {
          $error_levels = $_SESSION['html_auditor_reports_filter']['level'];
          return in_array($report['level'], $error_levels);
        });
      }
      // Get reports count.
      $reports_length = count($reports);
      // Get page id.
      $page = pager_find_page();
      // Initialize pager.
      pager_default_initialize($reports_length, self::REPORTS_MAX_LENGTH);
      // Chunk reports array.
      $reports = array_chunk($reports, self::REPORTS_MAX_LENGTH);
      // Sort reports.
      if (isset($reports[$page])) {
        $type = \Drupal::request()->query->get('order', '');
        $sort = \Drupal::request()->query->get('sort', '');
        usort($reports[$page], function($prev, $next) use ($type) {
          if (isset($prev[$type], $next[$type])) {
            return strcmp($prev[$type], $next[$type]);
          }
        });
        if ($sort === 'desc') {
          $reports[$page] = array_reverse($reports[$page]);
        }
      }
      // Update filenames using URLs instead.
      $maps = \Drupal::service('html_auditor.finder');
      $maps->files()->in($directory)->name('map.json');
      foreach ($maps as $map) {
        $maps = Json::decode($map->getContents());
      }
      foreach ($reports as $i => $report) {
        foreach ($report as $j => $data) {
          $uri = $maps[$reports[$i][$j]['file']];
          $uri_parse = parse_url($uri);
          $reports[$i][$j]['file'] = $this->l($uri_parse['path'], Url::fromUri($uri));
        }
      }
    }
    // Get reports filter form.
    $build['reports_filter'] = $this->formBuilder->getForm('Drupal\html_auditor\Form\AuditorFilterForm');
    // Get reports.
    $build['reports'] = [
      '#theme' => 'table',
      '#header' => [
        ['data' => $this->t('url'), 'field' => 'url'],
        ['data' => $this->t('type'), 'field' => 'Type'],
        ['data' => $this->t('level'), 'field' => 'Level'],
        $this->t('Message'),
      ],
      '#rows' => isset($page, $reports[$page]) ? $reports[$page] : [],
      '#attached' => [
        'library' => [
          'html_auditor/report'
        ]
      ]
    ];
    $build['reports_pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}

