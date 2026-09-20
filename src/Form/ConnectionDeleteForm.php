<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Url;

/**
 * Confirms disconnecting an account.
 */
class ConnectionDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Disconnect %label?', ['%label' => $this->getEntity()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('The site stops posting to this account. What was already posted stays on the platform, and the log keeps its record. The key in the Key module is not deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Disconnect');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('crosspost.connections');
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl() {
    return Url::fromRoute('crosspost.connections');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeletionMessage() {
    return $this->t('%label is disconnected.', ['%label' => $this->getEntity()->label()]);
  }

}
