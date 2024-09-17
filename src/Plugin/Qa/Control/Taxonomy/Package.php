<?php

declare(strict_types = 1);

namespace Drupal\qa\Plugin\Qa\Control\Taxonomy;

use Drupal\qa\BasePackage;

/**
 * OSInet QA Plugin for Taxonomy.
 */
class Package extends BasePackage {

  /**
   * {@inheritdoc}
   */
  public function init() {
    $this->title = $this->t('Taxonomy quality controls');
    $this->description = $this->t('Look for orphan freetags, and inconsistent node tagging');
  }

}
