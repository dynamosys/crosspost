<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The filters above the site-wide log.
 *
 * Submits by GET, so a view of the log can be bookmarked.
 */
class LogFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'crosspost_log_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $states = [], array $places = []): array {
    $query = $this->getRequest()->query;
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'form--inline';
    $form['#attributes']['class'][] = 'clearfix';
    $form['#after_build'][] = [static::class, 'dropInternals'];
    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('Result'),
      '#options' => $states,
      '#empty_option' => $this->t('Any'),
      '#default_value' => $query->get('state', ''),
    ];
    $form['connection'] = [
      '#type' => 'select',
      '#title' => $this->t('Where'),
      '#options' => $places,
      '#empty_option' => $this->t('Anywhere'),
      '#default_value' => $query->get('connection', ''),
    ];
    $form['filter'] = ['#type' => 'container', '#attributes' => ['class' => ['form-actions', 'form-item']]];
    $form['filter']['submit'] = ['#type' => 'submit', '#value' => $this->t('Filter'), '#name' => ''];
    return $form;
  }

  /**
   * Keeps Drupal's own form fields out of the address.
   */
  public static function dropInternals(array $form): array {
    unset($form['form_build_id'], $form['form_token'], $form['form_id']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
