<?php


use Drupal\Core\Config\Config;
use Drupal\field\Entity\FieldStorageConfig;
use Exception;
use PDO;


/**
 * Example use in update function in MODULE.install:
 *
 * function MODULE_update_9111() {
 *
 *   CustomFieldUtil::changeCustomFieldType('node', 'field_intro', 'text_long');
 *   CustomFieldUtil::increaseCustomFieldLength('media', 'field_media_caption', 512);
 *
 *   // renaming a custom field: make field with new name (UI) + copy data + remove old field (UI)
 *   CustomFieldUtil::copyDataBetweenFields('node',  'field_blabla', 'field_blabla_2');
 * }
 */
class CustomFieldUtil {


  public static function increaseCustomFieldLength(string $entityTypeId, string $fieldName, int $newLength) {
    self::alterCustomFieldStorageConfig($entityTypeId, $fieldName,
      function (Config $config) use ($newLength) {
        $config->set('settings.max_length', $newLength);
        return $config;
      }
    );
    self::updateCustomFieldStorageWithDataRestore($entityTypeId, $fieldName,
      function (FieldStorageConfig $fieldStorageConfig) use ($newLength) {
        $fieldStorageConfig->setSetting('max_length', $newLength);
        return $fieldStorageConfig;
      }
    );
    self::flushCaches();
  }


  public static function changeCustomFieldType(string $entityTypeId, string $fieldName, string $newType) {
    self::alterCustomFieldStorageConfig($entityTypeId, $fieldName,
      function (Config $config) use ($newType) {
        $config->set('settings.type', $newType);
        return $config;
      }
    );
    self::updateCustomFieldStorageWithDataRestore($entityTypeId, $fieldName,
      function (FieldStorageConfig $fieldStorageConfig) use ($newType) {
        $fieldStorageConfig->setSetting('type', $newType);
        return $fieldStorageConfig;
      }
    );
    self::flushCaches();
  }


  private static function alterCustomFieldStorageConfig($entityTypeId, $fieldName, $configUpdateFunction) {
    $name = 'field.storage.' . $entityTypeId . "." . $fieldName;
    $config = \Drupal::configFactory()
      ->getEditable($name);
    $config = $configUpdateFunction($config);
    $config->save(TRUE);
  }


  public static function copyDataBetweenFields(string $entityTypeId, string $fromFieldName, string $toFieldName) {
    // prepare
    $database = \Drupal::database();

    // from
    $fromTable = "{$entityTypeId}__$fromFieldName";
    $fromTableExists = $database->schema()->tableExists($fromTable);
    $fromTableRevision = "{$entityTypeId}_revision__$fromFieldName";
    $fromTableRevisionExists = $database->schema()->tableExists($fromTableRevision);

    // to
    $toTable = "{$entityTypeId}__$toFieldName";
    $toTableExists = $database->schema()->tableExists($toTable);
    $toTableRevision = "{$entityTypeId}_revision__$toFieldName";
    $toTableRevisionExists = $database->schema()->tableExists($toTableRevision);

    // sanity check
    if(!$fromTableExists) {
      throw new Exception("Cannot find source table '$fromTable' - does the field '$fromFieldName' exist?");
    }
    if(!$toTableExists) {
      throw new Exception("Cannot find target table '$toTable' - does the field '$toFieldName' exist?");
    }
    if(!$toTableExists) {
      throw new Exception("Cannot find target table '$toTable' - does the field exist?");
    }
    if($fromTableRevisionExists !== $toTableRevisionExists) {
      throw new Exception("Both tables need to be revisionable");
    }

    // get data
    $data = self::readAllValuesFromCustomFieldTable($fromTable);
    $dataRevision = $fromTableRevisionExists ? self::readAllValuesFromCustomFieldTable($fromTableRevision) : [];

    $fieldNameMappingFunction = function($k) use ($fromFieldName, $toFieldName) {
      return str_starts_with($k, $fromFieldName) ? str_replace($fromFieldName, $toFieldName, $k) : $k;
    };

    // clear data
    self::clearCustomFieldTable($toTable);
    if($toTableRevisionExists) {
      self::clearCustomFieldTable($toTableRevision);
    }

    // copy data
    self::insertValuesIntoCustomFieldTable($toTable, $data, $fieldNameMappingFunction);
    if($fromTableRevisionExists) {
      self::insertValuesIntoCustomFieldTable($toTableRevision, $dataRevision, $fieldNameMappingFunction);
    }
  }


  private static function updateCustomFieldStorageWithDataRestore(string $entityTypeId, string $fieldName, $fieldStorageDefinitionUpdateFunction) {
    // prepare
    $database = \Drupal::database();
    $entityDefinitionUpdateManager = \Drupal::entityDefinitionUpdateManager();

    $table = "{$entityTypeId}__$fieldName";
    $tableRevision = "{$entityTypeId}_revision__$fieldName";
    $tableRevisionExists = $database->schema()->tableExists($tableRevision);

    // get data
    $data = self::readAllValuesFromCustomFieldTable($table);
    $dataRevision = $tableRevisionExists ? self::readAllValuesFromCustomFieldTable($tableRevision) : [];

    // clear data
    self::clearCustomFieldTable($table);
    if($tableRevisionExists) {
      self::clearCustomFieldTable($tableRevision);
    }

    // get definition
    $fieldStorageDefinition = $entityDefinitionUpdateManager->getFieldStorageDefinition($fieldName, $entityTypeId);
    // delegate to closure
    $fieldStorageDefinitionUpdateFunction($fieldStorageDefinition);
    // update
    $entityDefinitionUpdateManager->updateFieldStorageDefinition($fieldStorageDefinition);

    // restore data
    self::insertValuesIntoCustomFieldTable($table, $data);
    if($tableRevisionExists) {
      self::insertValuesIntoCustomFieldTable($tableRevision, $dataRevision);
    }
  }


  private static function readAllValuesFromCustomFieldTable(string $table): array {
    $database = \Drupal::database();
    return $database->select($table)
      ->fields($table)
      ->execute()
      ->fetchAll(PDO::FETCH_ASSOC);
  }

  private static function clearCustomFieldTable(string $table): void {
    $database = \Drupal::database();
    $database->truncate($table)->execute();
  }

  private static function insertValuesIntoCustomFieldTable(string $table, array $data, $fieldNameMappingFunction = null): void {
    if(empty($data)) {
      return;
    }
    $database = \Drupal::database();
    $queryFields = array_keys(end($data));
    if(!empty($fieldNameMappingFunction)) {
      $queryFields = array_map($fieldNameMappingFunction, $queryFields);
    }

    foreach ($data as $row) {
      $values = array_values($row);

      $insertQuery = $database
        ->insert($table)
        ->fields($queryFields);
      $insertQuery->values($values);
      $insertQuery->execute();
    }
  }


  // warnings are printed when 'drush updb' is executed
  private static function printInfo(string $message): void {
    \Drupal::logger('UpdateCustomFieldUtil')->warning($message);
  }

  private static function flushCaches(bool $flushCache = true): void {
    if($flushCache) {
      self::printInfo("Flushing caches");
      drupal_flush_all_caches();
    }
  }

}
