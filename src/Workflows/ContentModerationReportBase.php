<?php

namespace Drupal\qa\Workflows;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

abstract class ContentModerationReportBase implements ContainerInjectionInterface {

  use StringTranslationTrait;

  protected $stateStorage;

  protected $transStorage;

  protected function buildGrid() {
    $grid = [];
    $stateIds = array_keys($this->getStates());
    $stateCells = array_map(function ($stateId) {
      return [];
    }, array_flip($stateIds));

    foreach ($stateIds as $stateId) {
      $grid[$stateId] = $stateCells;
    }

    foreach ($this->getTrans() as $transId => $trans) {
      $grid[$trans['from']][$trans['to']][] = $transId;
    }

    return $grid;
  }

  public function getDuplicateTransLabels() {
    $labels = [];
    foreach ($this->getTrans() as $trans) {
      if (isset($labels[$trans['label']])) {
        $labels[$trans['label']]++;
      }
      else {
        $labels[$trans['label']] = 1;
      }
    }
    $repeatedLabels = array_filter($labels, function ($count) {
      return $count > 1;
    });

    return $repeatedLabels;
  }

  protected function getStates() {
    $fullStates = $this->stateStorage->loadMultiple();
    $simpleStates = array_map(function ($entity) {
      return $entity->label();
    }, $fullStates);
    asort($simpleStates);
    return $simpleStates;
  }

  protected function getTrans() {
    $fullTrans = $this->transStorage->loadMultiple();
    $simpleTrans = array_map(function ($trans) {
      return [
        'label' => $trans->label(),
        'from' => $trans->getFromState(),
        'to' => $trans->getToState(),
      ];
    }, $fullTrans);

    return $simpleTrans;
  }

  public abstract function report();
}
