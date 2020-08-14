<?php

declare(strict_types = 1);

namespace Drupal\qa\Plugin\QaCheck\References;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\qa\Pass;
use Drupal\qa\Plugin\QaCheckBase;
use Drupal\qa\Plugin\QaCheckInterface;
use Drupal\qa\Result;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integrity checks for broken entity references.
 *
 * It covers core entity_reference only.
 *
 * Future versions are expected to cover
 * - core: file
 * - core: image
 * - contrib: entity_reference_revisions, notably used by paragraphs.
 * - contrib: dynamic_entity_reference
 *
 * For entity_reference_revisions, it will check references both up and down
 * the
 * parent/child chain.
 *
 * @QaCheck(
 *   id = "references.integrity",
 *   label=@Translation("Referential integrity"),
 *   details=@Translation("This check finds broken entity references. Missing
 *   nodes or references mean broken links and a bad user experience. These
 *   should usually be edited."), usesBatch=true, steps=3,
 * )
 */
class Integrity extends QaCheckBase implements QaCheckInterface {
  const NAME = 'references.integrity';

  const STEP_ER = 'entity_reference';
  const STEP_FILE = 'file';
  const STEP_IMAGE = 'image';
  const STEP_ERR = 'entity_reference_revisions';
  const STEP_DER = 'dynamic_entity_reference';

  /**
   * The config.factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The entity_type.manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $etm;

  /**
   * A map of storage handler by entity_type ID.
   *
   * @var array
   */
  protected $storages;

  /**
   * SystemUnusedExtensions constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $id
   *   The plugin ID.
   * @param array $definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $etm
   *   The entity_type.manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config.factory service.
   */
  public function __construct(
    array $configuration,
    string $id,
    array $definition,
    EntityTypeManagerInterface $etm,
    ConfigFactoryInterface $config
  ) {
    parent::__construct($configuration, $id, $definition);
    $this->config = $config;
    $this->etm = $etm;

    $this->cacheStorages();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $id,
    $definition
  ) {
    $etm = $container->get('entity_type.manager');
    $config = $container->get('config.factory');
    return new static($configuration, $id, $definition, $etm, $config);
  }

  /**
   * Fetch and cache the storage handlers per entity type for repeated use.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function cacheStorages(): void {
    $ets = array_keys($this->etm->getDefinitions());
    $handlers = [];
    foreach ($ets as $et) {
      $handlers[$et] = $this->etm->getStorage($et);
    }
    $this->storages = $handlers;
  }

  /**
   * Build a map of entity_reference fields per entity_type.
   *
   * @return array
   *   The map.
   */
  protected function getEntityReferenceFields(): array {
    $fscStorage = $this->storages['field_storage_config'];
    $defs = $fscStorage->loadMultiple();
    $fields = [];
    /** @var \Drupal\field\FieldStorageConfigInterface $fsc */
    foreach ($defs as $fsc) {
      if ($fsc->getType() !== self::STEP_ER) {
        continue;
      }
      $et = $fsc->getTargetEntityTypeId();
      $name = $fsc->getName();
      $target = $fsc->getSetting('target_type');
      // XXX hard-coded knowledge. Maybe refactor once multiple types are used.
      // $prop = $fsc->getMainPropertyName();
      if (!isset($fields[$et])) {
        $fields[$et] = [];
      }
      $fields[$et][$name] = $target;
    }
    return $fields;
  }

  /**
   * Verifies integrity of entity_reference forward links.
   *
   * @return \Drupal\qa\Result
   *   The sub-check results.
   */
  public function checkEntityReference(): Result {
    $fieldMap = $this->getEntityReferenceFields();
    $checks = [];
    foreach ($fieldMap as $et => $fields) {
      $checks[$et] = [
        // <id> => [ <field_name> => <target_id> ],
      ];
      $entities = $this->storages[$et]->loadMultiple();
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      foreach ($entities as $entity) {
        $checks[$et][$entity->id()] = [];
        foreach ($fields as $name => $targetET) {
          if (!$entity->hasField($name)) {
            continue;
          }
          $target = $entity->get($name);
          if ($target->isEmpty()) {
            continue;
          }
          $checks[$et][$entity->id()][$name] = [];
          /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $value */
          foreach ($target as $delta => $value) {
            $targetID = $value->toArray()[EntityReferenceItem::mainPropertyName()];
            foreach ($entity->referencedEntities() as $targetEntity) {
              $x = $targetEntity->getEntityTypeId();
              if ($x != $targetET) {
                continue;
              }
              // Target found, next delta.
              $x = $targetEntity->id();
              if ($x === $targetID) {
                continue 2;
              }
            }
            // Target not found: broken reference.
            $checks[$et][$entity->id()][$name][$delta] = $targetID;
          }
          if (empty($checks[$et][$entity->id()][$name])) {
            unset($checks[$et][$entity->id()][$name]);
          }
        }
        if (empty($checks[$et][$entity->id()])) {
          unset($checks[$et][$entity->id()]);
        }
      }
      if (empty($checks[$et])) {
        unset($checks[$et]);
      }
    }
    return new Result(self::STEP_ER, empty($checks), $checks);
  }

  /**
   * Verifies integrity of dynamic_entity_reference forward links.
   *
   * @return \Drupal\qa\Result
   *   The sub-check results.
   */
  public function checkDynamicEntityReference(): ?Result {
    return NULL;
  }

  /**
   * Verifies entity_reference_revisions forward and backward links.
   *
   * @return \Drupal\qa\Result
   *   The sub-check results.
   */
  public function checkEntityReferenceRevisions(): ?Result {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function run(): Pass {
    $pass = parent::run();
    $pass->record($this->checkEntityReference());
    $pass->life->modify();
    $pass->record($this->checkDynamicEntityReference());
    $pass->life->modify();
    $pass->record($this->checkEntityReferenceRevisions());
    $pass->life->end();
    return $pass;
  }

}
