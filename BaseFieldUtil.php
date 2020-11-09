<?php

use Drupal\Core\Config\Config;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldStorageConfig;


/**
 * Example use in update function in MODULE.install:
 *
 * function MODULE_update_9111() {
 *
 *   $fields['some_type'] = BaseFieldDefinition::create('list_string')
 *      ->setRequired(TRUE)
 *      ->setSetting('max_length', 25);
 *
 *   BaseFieldUtil::installBaseFields('some_type',  $fields);
 *   BaseFieldUtil::uninstallBaseField('paragraph', 'field_content');
 *   BaseFieldUtil::changeBaseFieldType('node', 'field_intro', 'text_long');
 *   BaseFieldUtil::increaseBaseFieldLength('media', 'field_media_caption', 512);
 *   BaseFieldUtil::changeBaseFieldName('user', 'old_field_name', 'new_field_name', $fields['some_type']);
 * }
 */
class BaseFieldUtil {

  public static function installBaseFields(string $entityTypeId, array $fields, bool $flushCache = true): void {
    self::printInfo("Start installing base fields for entity type $entityTypeId");
    foreach($fields as $fieldName => $fieldDefinition) {
      self::installBaseField($entityTypeId, $fieldName, $fieldDefinition, false);
    }
    self::printInfo("Done installing base fields for entity type $entityTypeId");
    self::flushCaches($flushCache);
  }


  public static function installBaseField(string $entityTypeId, string $fieldName, FieldStorageDefinitionInterface $fieldDefinition, bool $flushCache = true): void {
    $updateManager = \Drupal::entityDefinitionUpdateManager();
    self::printInfo("Start installing base field $fieldName for entity type $entityTypeId");
    // uninstall if present
    $fieldStorageDefinition = $updateManager->getFieldStorageDefinition($fieldName, $entityTypeId);
    self::printInfo("Found FieldStorageDefinition: " . (empty($fieldStorageDefinition) ? 'no' : 'yes'));
    if(!empty($fieldStorageDefinition)) {
      self::printInfo("Uninstalling field $fieldName for entity type $entityTypeId");
      $updateManager->uninstallFieldStorageDefinition($fieldStorageDefinition);
    }
    self::printInfo("Installing field $fieldName for entity type $entityTypeId");
    $updateManager->installFieldStorageDefinition($fieldName, $entityTypeId, $entityTypeId, $fieldDefinition);
    self::printInfo("Done installing base field $fieldName for entity type $entityTypeId");
    self::flushCaches($flushCache);
  }


  public static function uninstallBaseField(string $entityTypeId, string $fieldName, bool $flushCache = true): void {
    self::printInfo("Start uninstalling base field $fieldName for entity type $entityTypeId");
    $updateManager = \Drupal::entityDefinitionUpdateManager();
    // uninstall if present
    if ($fieldStorageDefinition = $updateManager->getFieldStorageDefinition($fieldName, $entityTypeId)) {
      self::printInfo("Uninstalling field $fieldName for entity type $entityTypeId");
      $updateManager->uninstallFieldStorageDefinition($fieldStorageDefinition);
    }
    self::printInfo("Done uninstalling base field $fieldName for entity type $entityTypeId");
    self::flushCaches($flushCache);
  }


  public static function increaseBaseFieldLength(string $entityTypeId, string $fieldName, int $newLength) {
    self::alterBaseFieldStorageConfig($entityTypeId, $fieldName,
      function (Config $config) use ($newLength) {
        $config->set('settings.max_length', $newLength);
        return $config;
      }
    );
    self::updateBaseFieldStorageWithDataRestore($entityTypeId, $fieldName,
      function (FieldStorageConfig $fieldStorageConfig) use ($newLength) {
        $fieldStorageConfig->setSetting('max_length', $newLength);
        return $fieldStorageConfig;
      }
    );
    self::flushCaches();
  }


  public static function changeBaseFieldType(string $entityTypeId, string $fieldName, string $newType) {
    self::alterBaseFieldStorageConfig($entityTypeId, $fieldName,
      function (Config $config) use ($newType) {
        $config->set('settings.type', $newType);
        return $config;
      }
    );
    self::updateBaseFieldStorageWithDataRestore($entityTypeId, $fieldName,
      function (FieldStorageConfig $fieldStorageConfig) use ($newType) {
        $fieldStorageConfig->setSetting('type', $newType);
        return $fieldStorageConfig;
      }
    );
    self::flushCaches();
  }


  public static function changeBaseFieldName(string $entityTypeId, string $oldFieldName, string $newFieldName, BaseFieldDefinition $baseFieldDefinition) {
    self::installBaseField($entityTypeId, $newFieldName, $baseFieldDefinition);
    self::copyDataBetweenFields($entityTypeId, $oldFieldName, $newFieldName);
    self::uninstallBaseField($entityTypeId, $oldFieldName);
  }


  private static function alterBaseFieldStorageConfig($entityTypeId, $fieldName, $configUpdateFunction) {
    $name = 'field.storage.' . $entityTypeId . "." . $fieldName;
    $config = \Drupal::configFactory()
      ->getEditable($name);
    $config = $configUpdateFunction($config);
    $config->save(TRUE);
  }


  private static function copyDataBetweenFields(string $entityTypeId, string $fromFieldName, string $toFieldName) {
    // prepare
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityDefinitionUpdateManager = \Drupal::entityDefinitionUpdateManager();

    $entityDefinition = $entityTypeManager->getDefinition($entityTypeId);
    $entityStorage = $entityTypeManager->getStorage($entityTypeId);

    $idKey = $entityDefinition->getKey('id');

    $tableName = $entityStorage->getDataTable() ?: $entityStorage->getBaseTable();
    $tableRevision = $entityStorage->getRevisionDataTable() ?: $entityStorage->getRevisionTable();

    // get data
    $data = self::readAllValuesFromBaseField($tableName, $fromFieldName, $idKey);
    $dataRevision = $entityDefinition->isRevisionable() ? self::readAllValuesFromBaseField($tableRevision, $fromFieldName, $idKey) : [];

    // restore data
    self::insertValuesIntoBaseField($tableName, $toFieldName, $idKey, $data);
    if($entityDefinition->isRevisionable()) {
      self::insertValuesIntoBaseField($tableRevision, $toFieldName, $idKey, $dataRevision);
    }
  }


  private static function updateBaseFieldStorageWithDataRestore(string $entityTypeId, string $fieldName, $fieldStorageDefinitionUpdateFunction) {
    // prepare
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityDefinitionUpdateManager = \Drupal::entityDefinitionUpdateManager();

    $entityDefinition = $entityTypeManager->getDefinition($entityTypeId);
    $entityStorage = $entityTypeManager->getStorage($entityTypeId);

    $idKey = $entityDefinition->getKey('id');

    $tableName = $entityStorage->getDataTable() ?: $entityStorage->getBaseTable();
    $tableRevision = $entityStorage->getRevisionDataTable() ?: $entityStorage->getRevisionTable();

    // get data
    $data = self::readAllValuesFromBaseField($tableName, $fieldName, $idKey);
    $dataRevision = $entityDefinition->isRevisionable() ? self::readAllValuesFromBaseField($tableRevision, $fieldName, $idKey) : [];

    // clear data
    self::clearBaseField($tableName, $fieldName);
    if($entityDefinition->isRevisionable()) {
      self::clearBaseField($tableRevision, $fieldName);
    }

    // get definition
    $fieldStorageDefinition = $entityDefinitionUpdateManager->getFieldStorageDefinition($fieldName, $entityTypeId);
    // delegate to closure
    $fieldStorageDefinitionUpdateFunction($fieldStorageDefinition);
    // update
    $entityDefinitionUpdateManager->updateFieldStorageDefinition($fieldStorageDefinition);

    // restore data
    self::insertValuesIntoBaseField($tableName, $fieldName, $idKey, $data);
    if($entityDefinition->isRevisionable()) {
      self::insertValuesIntoBaseField($tableRevision, $fieldName, $idKey, $dataRevision);
    }
  }


  private static function readAllValuesFromBaseField(string $table, string $fieldName, $idKey): array {
    $database = \Drupal::database();
    return $status_values = $database->select($table)
      ->fields($table, [$idKey, $fieldName])
      ->execute()
      ->fetchAllKeyed();
  }

  private static function clearBaseField(string $table, string $fieldName): void {
    $database = \Drupal::database();
    $database->update($table)
      ->fields([$fieldName => NULL])
      ->execute();
  }

  private static function insertValuesIntoBaseField(string $table, string $fieldName, $idKey, array $data): void {
    if(empty($data)) {
      return;
    }
    $database = \Drupal::database();

    foreach ($data as $id => $value) {
      $database->update($table)
        ->fields([$fieldName => $value])
        ->condition($idKey, $id)
        ->execute();
    }
  }


  // warnings are printed when 'drush updb' is executed
  private static function printInfo(string $message): void {
    \Drupal::logger('UpdateBaseFieldUtil')->warning($message);
  }

  private static function flushCaches(bool $flushCache = true): void {
    if($flushCache) {
      self::printInfo("Flushing caches");
      drupal_flush_all_caches();
    }
  }

}
