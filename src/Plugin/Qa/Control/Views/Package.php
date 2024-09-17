<?php

declare(strict_types = 1);

namespace Drupal\qa\Plugin\Qa\Control\Views;

use Drupal\qa\BasePackage;

/**
 * OSInet QA Plugin for Views.
 */
class Package extends BasePackage {

  /**
   * {@inheritdoc}
   */
  public function init() {
    $this->title = $this->t('Views quality controls');
    $this->description = $this->t('Look for overridden views and views containing embedded PHP');
  }

}
