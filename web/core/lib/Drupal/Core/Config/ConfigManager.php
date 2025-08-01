<?php

namespace Drupal\Core\Config;

use Drupal\Component\Diff\Diff;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The ConfigManager provides helper functions for the configuration system.
 */
class ConfigManager implements ConfigManagerInterface {
  use StringTranslationTrait;
  use StorageCopyTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * The active configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The configuration collection info.
   *
   * @var \Drupal\Core\Config\ConfigCollectionInfo
   */
  protected $configCollectionInfo;

  /**
   * The configuration storages keyed by collection name.
   *
   * @var \Drupal\Core\Config\StorageInterface[]
   */
  protected $storages;

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * Creates ConfigManager objects.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Config\StorageInterface $active_storage
   *   The active configuration storage.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extension_path_resolver
   *   The extension path resolver.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, TranslationInterface $string_translation, StorageInterface $active_storage, EventDispatcherInterface $event_dispatcher, EntityRepositoryInterface $entity_repository, ExtensionPathResolver $extension_path_resolver) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->typedConfigManager = $typed_config_manager;
    $this->stringTranslation = $string_translation;
    $this->activeStorage = $active_storage;
    $this->eventDispatcher = $event_dispatcher;
    $this->entityRepository = $entity_repository;
    $this->extensionPathResolver = $extension_path_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeIdByName($name) {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (($entity_type instanceof ConfigEntityTypeInterface && $config_prefix = $entity_type->getConfigPrefix()) && str_starts_with($name, $config_prefix . '.')) {
        return $entity_type_id;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadConfigEntityByName($name) {
    $entity_type_id = $this->getEntityTypeIdByName($name);
    if ($entity_type_id) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $id = substr($name, strlen($entity_type->getConfigPrefix()) + 1);
      return $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigFactory() {
    return $this->configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function diff(StorageInterface $source_storage, StorageInterface $target_storage, $source_name, $target_name = NULL, $collection = StorageInterface::DEFAULT_COLLECTION) {
    if ($collection != StorageInterface::DEFAULT_COLLECTION) {
      $source_storage = $source_storage->createCollection($collection);
      $target_storage = $target_storage->createCollection($collection);
    }
    if (!isset($target_name)) {
      $target_name = $source_name;
    }
    // The output should show configuration object differences formatted as
    // YAML. But the configuration is not necessarily stored in files.
    // Therefore, they need to be read and parsed, and lastly, dumped into YAML
    // strings.
    $source_data = explode("\n", Yaml::encode($source_storage->read($source_name)));
    $target_data = explode("\n", Yaml::encode($target_storage->read($target_name)));

    // Check for new or removed files.
    if ($source_data === ['false']) {
      // Added file.
      // Cast the result of t() to a string, as the diff engine doesn't know
      // about objects.
      $source_data = [(string) $this->t('File added')];
    }
    if ($target_data === ['false']) {
      // Deleted file.
      // Cast the result of t() to a string, as the diff engine doesn't know
      // about objects.
      $target_data = [(string) $this->t('File removed')];
    }

    return new Diff($source_data, $target_data);
  }

  /**
   * {@inheritdoc}
   */
  public function createSnapshot(StorageInterface $source_storage, StorageInterface $snapshot_storage) {
    self::replaceStorageContents($source_storage, $snapshot_storage);
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall($type, $name) {
    $entities = $this->getConfigEntitiesToChangeOnDependencyRemoval($type, [$name], FALSE);
    // Fix all dependent configuration entities.
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    foreach ($entities['update'] as $entity) {
      $entity->save();
    }
    // Remove all dependent configuration entities.
    foreach ($entities['delete'] as $entity) {
      $entity->setUninstalling(TRUE);
      $entity->delete();
    }

    $config_names = $this->configFactory->listAll($name . '.');
    foreach ($config_names as $config_name) {
      $this->configFactory->getEditable($config_name)->delete();
    }

    // Remove any matching configuration from collections.
    foreach ($this->activeStorage->getAllCollectionNames() as $collection) {
      $collection_storage = $this->activeStorage->createCollection($collection);
      $overrider = $this->getConfigCollectionInfo()->getOverrideService($collection);
      foreach ($collection_storage->listAll($name . '.') as $config_name) {
        if ($overrider) {
          $config = $overrider->createConfigObject($config_name, $collection);
        }
        else {
          $config = new Config($config_name, $collection_storage, $this->eventDispatcher, $this->typedConfigManager);
        }
        $config->initWithData($collection_storage->read($config_name));
        $config->delete();
      }
    }

    $schema_dir = $this->extensionPathResolver->getPath($type, $name) . '/' . InstallStorage::CONFIG_SCHEMA_DIRECTORY;
    if (is_dir($schema_dir)) {
      // Refresh the schema cache if uninstalling an extension that provides
      // configuration schema.
      $this->typedConfigManager->clearCachedDefinitions();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigDependencyManager() {
    $dependency_manager = new ConfigDependencyManager();
    // Read all configuration using the factory. This ensures that multiple
    // deletes during the same request benefit from the static cache. Using the
    // factory also ensures configuration entity dependency discovery has no
    // dependencies on the config entity classes. Assume data with UUID is a
    // config entity. Only configuration entities can be depended on so we can
    // ignore everything else.
    $data = array_map(function ($config) {
      $data = $config->get();
      if (isset($data['uuid'])) {
        return $data;
      }
      return FALSE;
    }, $this->configFactory->loadMultiple($this->activeStorage->listAll()));
    $dependency_manager->setData(array_filter($data));
    return $dependency_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function findConfigEntityDependencies($type, array $names, ?ConfigDependencyManager $dependency_manager = NULL) {
    if (!$dependency_manager) {
      $dependency_manager = $this->getConfigDependencyManager();
    }
    $dependencies = [];
    foreach ($names as $name) {
      $dependencies[] = $dependency_manager->getDependentEntities($type, $name);
    }
    return array_merge(...$dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public function findConfigEntityDependenciesAsEntities($type, array $names, ?ConfigDependencyManager $dependency_manager = NULL) {
    $dependencies = $this->findConfigEntityDependencies($type, $names, $dependency_manager);
    $entities = [];
    $definitions = $this->entityTypeManager->getDefinitions();
    foreach ($dependencies as $config_name => $dependency) {
      // Group by entity type to efficient load entities using
      // \Drupal\Core\Entity\EntityStorageInterface::loadMultiple().
      $entity_type_id = $this->getEntityTypeIdByName($config_name);
      // It is possible that a non-configuration entity will be returned if a
      // simple configuration object has a UUID key. This would occur if the
      // dependents of the system module are calculated since system.site has
      // a UUID key.
      if ($entity_type_id) {
        $id = substr($config_name, strlen($definitions[$entity_type_id]->getConfigPrefix()) + 1);
        $entities[$entity_type_id][$config_name] = $id;
      }
    }
    // Align the order of entities returned to the dependency order by first
    // populating the keys in the same order.
    $entities_to_return = array_fill_keys(array_keys($dependencies), NULL);
    foreach ($entities as $entity_type_id => $entities_to_load) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $loaded_entities = $storage->loadMultiple($entities_to_load);
      foreach ($loaded_entities as $loaded_entity) {
        $entities_to_return[$loaded_entity->getConfigDependencyName()] = $loaded_entity;
      }
    }
    // Return entities list with NULL entries removed.
    return array_filter($entities_to_return);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigEntitiesToChangeOnDependencyRemoval($type, array $names, $dry_run = TRUE) {
    $dependency_manager = $this->getConfigDependencyManager();

    // Store the list of dependents in three separate variables. This allows us
    // to determine how the dependency graph changes as entities are fixed by
    // calling the onDependencyRemoval() method.

    // The list of original dependents on $names. This list never changes.
    $original_dependents = $this->findConfigEntityDependenciesAsEntities($type, $names, $dependency_manager);

    // The current list of dependents on $names. This list is recalculated when
    // calling an entity's onDependencyRemoval() method results in the entity
    // changing. This list is passed to each entity's onDependencyRemoval()
    // method as the list of affected entities.
    $current_dependents = $original_dependents;

    // The list of dependents to process. This list changes as entities are
    // processed and are either fixed or deleted.
    $dependents_to_process = $original_dependents;

    // Initialize other variables.
    $affected_uuids = [];
    $return = [
      'update' => [],
      'delete' => [],
      'unchanged' => [],
    ];

    // Try to fix the dependents and find out what will happen to the dependency
    // graph. Entities are processed in the order of most dependent first. For
    // example, this ensures that Menu UI third party dependencies on node types
    // are fixed before processing the node type's other dependents.
    while ($dependent = array_pop($dependents_to_process)) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $dependent */
      if ($dry_run) {
        // Clone the entity so any changes do not change any static caches.
        $dependent = clone $dependent;
      }
      $fixed = FALSE;
      if ($this->callOnDependencyRemoval($dependent, $current_dependents, $type, $names)) {
        // Recalculate dependencies and update the dependency graph data.
        $dependent->calculateDependencies();
        $dependency_manager->updateData($dependent->getConfigDependencyName(), $dependent->getDependencies());
        // Based on the updated data rebuild the list of current dependents.
        // This will remove entities that are no longer dependent after the
        // recalculation.
        $current_dependents = $this->findConfigEntityDependenciesAsEntities($type, $names, $dependency_manager);
        // Rebuild the list of entities that we need to process using the new
        // list of current dependents and removing any entities that we've
        // already processed.
        $dependents_to_process = array_filter($current_dependents, function ($current_dependent) use ($affected_uuids) {
          return !in_array($current_dependent->uuid(), $affected_uuids);
        });
        // Ensure that the dependent has actually been fixed. It is possible
        // that other dependencies cause it to still be in the list.
        $fixed = TRUE;
        foreach ($dependents_to_process as $key => $entity) {
          if ($entity->uuid() == $dependent->uuid()) {
            $fixed = FALSE;
            unset($dependents_to_process[$key]);
            break;
          }
        }
        if ($fixed) {
          $affected_uuids[] = $dependent->uuid();
          $return['update'][] = $dependent;
        }
      }
      // If the entity cannot be fixed then it has to be deleted.
      if (!$fixed) {
        $affected_uuids[] = $dependent->uuid();
        // Deletes should occur in the order of the least dependent first. For
        // example, this ensures that fields are removed before field storages.
        array_unshift($return['delete'], $dependent);
      }
    }
    // Use the list of affected UUIDs to filter the original list to work out
    // which configuration entities are unchanged.
    $return['unchanged'] = array_filter($original_dependents, function ($dependent) use ($affected_uuids) {
      return !(in_array($dependent->uuid(), $affected_uuids));
    });

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigCollectionInfo() {
    if (!isset($this->configCollectionInfo)) {
      $this->configCollectionInfo = new ConfigCollectionInfo();
      $this->eventDispatcher->dispatch($this->configCollectionInfo, ConfigCollectionEvents::COLLECTION_INFO);
    }
    return $this->configCollectionInfo;
  }

  /**
   * Calls an entity's onDependencyRemoval() method.
   *
   * A helper method to call onDependencyRemoval() with the correct list of
   * affected entities. This list should only contain dependencies on the
   * entity. Configuration and content entity dependencies will be converted
   * into entity objects.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity to call onDependencyRemoval() on.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface[] $dependent_entities
   *   The list of dependent configuration entities.
   * @param string $type
   *   The type of dependency being checked. Either 'module', 'theme', 'config'
   *   or 'content'.
   * @param array $names
   *   The specific names to check. If $type equals 'module' or 'theme' then it
   *   should be a list of module names or theme names. In the case of 'config'
   *   or 'content' it should be a list of configuration dependency names.
   *
   * @return bool
   *   TRUE if the entity has changed as a result of calling the
   *   onDependencyRemoval() method, FALSE if not.
   */
  protected function callOnDependencyRemoval(ConfigEntityInterface $entity, array $dependent_entities, $type, array $names) {
    $entity_dependencies = $entity->getDependencies();
    if (empty($entity_dependencies)) {
      // No dependent entities nothing to do.
      return FALSE;
    }

    $affected_dependencies = [
      'config' => [],
      'content' => [],
      'module' => [],
      'theme' => [],
    ];

    // Work out if any of the entity's dependencies are going to be affected.
    if (isset($entity_dependencies[$type])) {
      // Work out which dependencies the entity has in common with the provided
      // $type and $names.
      $affected_dependencies[$type] = array_intersect($entity_dependencies[$type], $names);

      // If the dependencies are entities we need to convert them into objects.
      if ($type == 'config' || $type == 'content') {
        $affected_dependencies[$type] = array_map(function ($name) use ($type) {
          if ($type == 'config') {
            return $this->loadConfigEntityByName($name);
          }
          else {
            // Ignore the bundle.
            [$entity_type_id,, $uuid] = explode(':', $name);
            return $this->entityRepository->loadEntityByConfigTarget($entity_type_id, $uuid);
          }
        }, $affected_dependencies[$type]);
      }
    }

    // Merge any other configuration entities into the list of affected
    // dependencies if necessary.
    if (isset($entity_dependencies['config'])) {
      foreach ($dependent_entities as $dependent_entity) {
        if (in_array($dependent_entity->getConfigDependencyName(), $entity_dependencies['config'])) {
          $affected_dependencies['config'][] = $dependent_entity;
        }
      }
    }

    // Key the entity arrays by config dependency name to make searching easy.
    foreach (['config', 'content'] as $dependency_type) {
      $affected_dependencies[$dependency_type] = array_combine(
        array_map(function ($entity) {
          return $entity->getConfigDependencyName();
        }, $affected_dependencies[$dependency_type]),
        $affected_dependencies[$dependency_type]
      );
    }

    // Inform the entity.
    return $entity->onDependencyRemoval($affected_dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public function findMissingContentDependencies() {
    $content_dependencies = [];
    $missing_dependencies = [];
    foreach ($this->activeStorage->readMultiple($this->activeStorage->listAll()) as $config_data) {
      if (isset($config_data['dependencies']['content'])) {
        $content_dependencies[] = $config_data['dependencies']['content'];
      }
      if (isset($config_data['dependencies']['enforced']['content'])) {
        $content_dependencies[] = $config_data['dependencies']['enforced']['content'];
      }
    }
    $unique_content_dependencies = array_unique(array_merge(...$content_dependencies));
    foreach ($unique_content_dependencies as $content_dependency) {
      // Format of the dependency is entity_type:bundle:uuid.
      [$entity_type, $bundle, $uuid] = explode(':', $content_dependency, 3);
      if (!$this->entityRepository->loadEntityByUuid($entity_type, $uuid)) {
        $missing_dependencies[$uuid] = [
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'uuid' => $uuid,
        ];
      }
    }
    return $missing_dependencies;
  }

}
