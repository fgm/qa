<?php

declare(strict_types = 1);

namespace Drupal\qa\Plugin\Qa\Control\I18n;

use Drupal\qa\BasePackage;

/**
 * OSInet QA Plugin for i18n (internationalization) module.
 */
class Package extends BasePackage {

  /**
   * {@inheritdoc}
   */
  public function init() {
    $this->title = $this->t('i18n');
    $this->description = $this->t('Inconsistent variables translation');
  }

}
