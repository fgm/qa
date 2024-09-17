<?php

namespace Drupal\qa\Plugin\Qa\Control\I18n;

use Drupal\qa\Pass;
use Drupal\qa\Plugin\Qa\Control\BaseControl;

/**
 * Find inconsistencies in {i18nvariables} and {languages}
 */
class Variables extends BaseControl {

  /**
   * @var string
   */
  protected $package_name;

  /**
   * @var \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected $description;

  /**
   * @var \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected $title;

  /**
   * {@inheritdoc]
   */
  public function init() {
    $this->package_name = __NAMESPACE__;
    $this->title = t('Inconsistent variables translation');
    $this->description = t('In most scenarios, when a variable is translated at least once, it ought to be translated in every language on the site, not more, not less.');
  }

  /**
   * Identify variables translations for languages not currently on site
   */
  public function checkExtra() {
    $languages = array_keys(language_list());
    $ph = db_placeholders($languages, 'char');
    $sq = <<<sql
SELECT v.name, v.language
FROM {i18n_variable} v
WHERE v.language NOT IN ($ph)
ORDER BY 1, 2
sql;
    $q = db_query($sq, $languages);
    // No db_rewrite_sql: this needs to be unhindered by any access control
    $vars = [];
    while ($o = db_fetch_object($q)) {
      $vars[$o->name][] = $o->language;
    }

    $items = [];
    foreach ($vars as $name => $var_languages) {
      $items[] = t('@var: @languages',
        ['@var' => $name, '@languages' => implode(', ', $var_languages)]);
    }
    $ret = [
      'name' => 'extra',
      'status' => empty($vars) ? 1 : 0,
      'result' => ['extra' => $items],
    ];
    return $ret;
  }

  /**
   * Identify variables for which at least one translation is missing
   */
  public function checkMissing() {
    $languages = array_keys(language_list());
    $ph = db_placeholders($languages, 'char');
    $sq = <<<sql
SELECT v.name, v.language
FROM {i18n_variable} v
WHERE v.language IN ($ph)
ORDER BY 1, 2
sql;
    $q = db_query($sq, $languages);
    // No db_rewrite_sql: this needs to be unhindered by any access control
    $vars = [];
    while ($o = db_fetch_object($q, $ph)) {
      $vars[$o->name][] = $o->language;
    }

    $items = [];
    foreach ($vars as $name => $var_languages) {
      $missing = array_diff($languages, $var_languages);
      if (!empty($missing)) {
        $items[] = t('@var: @languages',
          ['@var' => $name, '@languages' => implode(', ', $missing)]);
      }
    }

    $ret = [
      'name' => 'missing',
      'status' => empty($items) ? 1 : 0,
      'result' => ['missing' => $items],
    ];
    return $ret;
  }

  public static function getDependencies(): array {
    $ret = ['i18n']; // introduces {i18n_variable}
    return $ret;
  }

  public function run(): Pass {
    $pass = parent::run();
    $pass->record($this->checkExtra());
    $pass->life->modify();
    $pass->record($this->checkMissing());
    $pass->life->end();

    $extra_row = empty($pass->result['extra']['extra'])
      ? [['data' => t('No extra translation found'), 'colspan' => 2,]]
      : [
        t('Extra translations'),
        theme('item_list', $pass->result['extra']['extra']),
      ];

    $missing_row = empty($pass->result['missing']['missing'])
      ? [
        [
          'data' => t('No missing translation found'),
          'colspan' => 2,
        ],
      ]
      : [
        t('Missing translations'),
        theme('item_list', $pass->result['missing']['missing']),
      ];

    $rows = [$extra_row, $missing_row];
    $pass->result = theme('table', NULL, $rows);
    return $pass;
  }
}
