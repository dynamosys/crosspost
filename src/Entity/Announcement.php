<?php

declare(strict_types=1);

namespace Drupal\crosspost\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\node\NodeInterface;

/**
 * Defines the announcement: one piece of content going to one connection.
 *
 * The announcements are the log, and what stops anything going out twice.
 *
 * @ContentEntityType(
 *   id = "crosspost_announcement",
 *   label = @Translation("Crosspost announcement"),
 *   label_collection = @Translation("Crosspost announcements"),
 *   label_singular = @Translation("announcement"),
 *   label_plural = @Translation("announcements"),
 *   label_count = @PluralTranslation(
 *     singular = "@count announcement",
 *     plural = "@count announcements",
 *   ),
 *   handlers = {
 *     "storage_schema" = "Drupal\crosspost\AnnouncementStorageSchema",
 *   },
 *   base_table = "crosspost_announcement",
 *   admin_permission = "administer crosspost",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class Announcement extends ContentEntityBase implements AnnouncementInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getState(): string {
    return (string) $this->get('state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setState(string $state): static {
    $this->set('state', $state);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getNode(): ?NodeInterface {
    $node = $this->get('node')->entity;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectionId(): string {
    return (string) $this->get('connection')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getText(): string {
    return (string) $this->get('text')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Content'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE);
    $fields['connection'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Connection'))
      ->setSetting('max_length', 64);
    $fields['place'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Where'))
      ->setDescription(t('The platform and account as they were called when this was shared.'))
      ->setSetting('max_length', 255);
    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'));
    $fields['state'] = BaseFieldDefinition::create('string')
      ->setLabel(t('State'))
      ->setSetting('max_length', 16)
      ->setDefaultValue(AnnouncementInterface::WAITING);
    $fields['automatic'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Shared by itself'))
      ->setDefaultValue(FALSE);
    $fields['tries'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Tries'))
      ->setDefaultValue(0);
    $fields['queued'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Waiting since'));
    $fields['next_try'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Next try'));
    $fields['posted'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Posted'));
    $fields['remote_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t("The platform's ID for the post"))
      ->setSetting('max_length', 255);
    $fields['remote_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Where the post can be seen'));
    $fields['reply'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('What the platform said'));
    $fields['http_status'] = BaseFieldDefinition::create('integer')
      ->setLabel(t("The HTTP status of the platform's answer"));
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Shared by'))
      ->setSetting('target_type', 'user');
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));
    return $fields;
  }

}
