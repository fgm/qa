<?php

namespace Drupal\qa\Workflows;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\qa\Workflows\ContentModerationReportBase;
use Grafizzi\Graph\Attribute;
use Grafizzi\Graph\Edge;
use Grafizzi\Graph\Graph;
use Grafizzi\Graph\Node;
use Pimple\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentModerationGraph extends ContentModerationReportBase implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The Pimple instance used by Grafizzi.
   *
   * @var \Pimple\Container
   */
  protected $pimple;

  public function __construct(ContentEntityStorageInterface $stateStorage, ConfigEntityStorageInterface $workflowStorage, Container $pimple) {
    $this->pimple = $pimple;
    $this->stateStorage = $stateStorage;
    $this->workflowStorage = $workflowStorage;
  }

  /**
   * @return \Grafizzi\Graph\Graph
   */
  protected function buildGraph() {
    $dic = $this->pimple;
    $g = new Graph($dic);
    $states = $this->getStates();
    $transList = $this->getTrans();

    $nodes = [];
    foreach ($states as $name => $label) {
      $nodes[$name] = new Node($dic, $name, [
        new Attribute($dic, 'label', "${label}\n${name}"),
      ]);
      $g->addChild($nodes[$name]);
    }

    foreach ($transList as $name => $trans) {
      $g->addChild(new Edge($dic,
        $nodes[$trans['from']],
        $nodes[$trans['to']],
        [new Attribute($dic, 'label', "${trans['label']}\n$name")]
      ));
    }

    return $g;
  }

  protected function buildRow(string $stateId, array $transList) {
    $states = $this->getStates();
    $trans = $this->getTrans();
    $row = ["${states[$stateId]}\n${stateId}"];
    foreach ($transList as $from => $transIds) {
      $cellArray = [];
      foreach ($transIds as $transId) {
        assert($trans[$transId]['from'] = $stateId);
        $transLabel = $trans[$transId]['label'];
      }
      $cellArray[] = $transLabel;
      $cellArray[] = $transId;
      $cellArray[] = "";
      $cell = implode("\n", $cellArray);
      $row[$trans[$transId]['to']] = $cell;
    }

    return $row;
  }

  public static function create(ContainerInterface $container) {
    /** @var EntityTypeManagerInterface $etm */
    $etm = $container->get('entity_type.manager');

    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $stateStorage */
    $stateStorage = $etm->getStorage('content_moderation_state');

    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.qa');

    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $stateStorage */
    $workflowStorage = $etm->getStorage('workflow');

    $pimple = new Container([
      'logger' => $logger,
      'directed' => TRUE,
    ]);

    return new static($stateStorage, $workflowStorage, $pimple);
  }

  public function report() {
    $grid = $this->buildGrid();
    return $this->buildGraph($grid)
      ->build();
  }

}
