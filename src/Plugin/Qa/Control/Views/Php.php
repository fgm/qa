<?php

namespace Drupal\qa\Plugin\Qa\Control\Views;

use Drupal\qa\Pass;

/**
 * Find views containing PHP code
 */
class Php extends Views {

  /**
   * {@inheritdoc]
   */
  public function init() {
    $this->package_name = __NAMESPACE__;
    $this->title = t('PHP code within views');
    $this->description = t('Is there any embedded PHP within views and display definitions ? This is both a security risk and a performance issue.');
  }

  /**
   * @param string $area
   *   The area (header, footer, empty) being examined.
   * @param array $php
   *   The array of input formats containing PHP.
   * @param \stdClass $display
   *   The display being examined.
   * @param string $area_name
   *   The name of the area
   *
   * @return array
   *   The array of PHP fragments found in the area.
   */
  protected function checkViews2Php($area, array $php, $display, $area_name) {
    $ret = [];
    $area_format = $display->display_options[$area_name .'_format']; // Always set
    if (in_array($area_format, $php)) {
      $ret['text'] = $area;
    }
    return $ret;
  }

  /**
   * @param array $area
   *   The area (header, footer, empty) being examined.
   * @param array $php
   *   The array of input formats containing PHP.
   *
   * @return array
   *   The array of PHP fragments found in the area.
   */
  protected function checkViews3Php(array $area, array $php) {
    $ret = [];
    foreach ($area as $field => $field_options) {
      if ($field_options['field'] == 'area' && isset($field_options['format']) && in_array($field_options['format'], $php)) {
        $ret[$field] = $field_options['content'];
      }
    }
    return $ret;
  }

  /**
   * Views 2 had a single string for areas whereas Views 3 has an array for them.
   */
  public function checkViewPhp($view) {
    $php = $this->getPhpFormats();
    $areas = ['header', 'footer', 'empty'];
    $result = [];

    foreach ($view->display as $display_name => $display) {
      foreach ($areas as $area_name) {
        if (!isset($display->display_options[$area_name])) {
          continue;
        }

        $area = $display->display_options[$area_name];
        $fragments = is_array($area)
          ? $this->checkViews3Php($area, $php)
          : $this->checkViews2Php($area, $php, $display, $area_name);

        if (!empty($fragments))  {
          $result[$display_name][$area_name] = $fragments;
        }
      } // foreach header, footer, empty...
    } // foreach display

    $ret = [
      'name' => $view->name,
      'status' => empty($result),
      'result' => $result,
    ];
    return $ret;
  }

  /**
   * Get a list of the ids of input formats containing the PHP eval filter.
   *
   * @return array
   */
  protected function getPhpFormats($reset = FALSE) {
    static $php = NULL;

    if (!isset($php) || $reset) {
      $formats = filter_formats();
      $php = [];
      foreach ($formats as $format) {
        $filters = filter_list_format($format->id());
        foreach ($filters as $filter) {
          if ($filter->module == 'php') {
            $php[] = $format->id();
            break;
          }
        }
      }
    }
    return $php;
  }

  public function run(): Pass {
    $pass = parent::run();
    $views = views_get_all_views(TRUE);
    foreach ($views as $view) {
      $pass->record($this->checkViewPhp($view));
    }
    $pass->life->end();

    if ($pass->status) {
      $result = format_plural(count($views), '1 view checked, none containing PHP',
        '@count views checked, none containing PHP', []);
    }
    else {
      $result = format_plural(count($views), '1 view checked and containing PHP',
        '@count views checked, @php containing PHP', [
          '@php' => count($pass->result),
        ]);
      $header = [
        t('View'),
        t('Display'),
        t('Area'),
        t('Field'),
        t('Content'),
      ];
      $data = [];
      foreach ($pass->result as $view_name => $displays) {
        $row = [];
        $link_title = empty($views[$view_name]->human_name)
          ? $view_name
          : $views[$view_name]->human_name;
        $view_link = l($link_title, "admin/structure/views/view/$view_name/edit");
        $row['view'] = ['data' => $view_link];
        foreach ($displays as $display_id => $areas) {
          $row['display'] = l($display_id, "admin/structure/views/view/$view_name/edit/$display_id");
          foreach ($areas as $area_name => $fields) {
            $row['area'] = l($area_name, "admin/structure/views/nojs/rearrange/$view_name/$display_id/$area_name");
            foreach ($fields as $field => $content) {
              $row['field'] = l($field,
                'admin/structure/views/nojs/config-item/'. $view_name .'/'. $display_id .'/'. $area_name .'/'. $field,
                ['query' => ['destination' => 'admin/reports/qa/results']]
              );
              $row['content'] = [
                'data'  => '<pre>'. check_plain($content) .'</pre>',
                'class' => 'pre',
              ];
              $data[$view_name .'/'. $display_id .'/'. $area_name .'/'. $field] = $row;
            }
          }
        }
      }
      ksort($data);
      $result .= theme('table', [
        'header' => $header,
        'rows' => $data,
      ]);
    }
    $pass->result = $result;
    return $pass;
  }
}

