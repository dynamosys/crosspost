<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Credentials;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A platform's setup guide, then the fields to connect one account.
 */
class ConnectForm extends FormBase {

  public function __construct(
    protected AdapterPluginManager $adapters,
    protected Credentials $credentials,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.crosspost_adapter'),
      $container->get('crosspost.credentials'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'crosspost_connect';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $adapter_id = ''): array {
    $adapter = $this->adapters->createInstance($adapter_id);
    $guide = $adapter->guide();
    $form['#attributes']['id'] = 'crosspost-guide';
    $form['#attributes']['class'][] = 'crosspost-guide';
    $form['adapter'] = ['#type' => 'value', '#value' => $adapter_id];
    $form['guide'] = [
      '#theme' => 'crosspost_guide',
      '#platform' => $adapter->label(),
      '#guide' => $guide,
    ];

    $has_secret = FALSE;
    foreach ($adapter->credentialFields() as $field) {
      if ($field->secret) {
        $has_secret = TRUE;
        $form['fields'][$field->name] = [
          '#type' => 'key_select',
          '#title' => $field->label,
          '#description' => $field->description,
          '#required' => $field->required,
          '#empty_option' => $this->t('Select a key'),
          '#parents' => ['keys', $field->name],
        ];
      }
      else {
        $form['fields'][$field->name] = [
          '#type' => 'textfield',
          '#title' => $field->label,
          '#description' => $field->description,
          '#default_value' => $field->default,
          '#required' => $field->required,
          '#parents' => ['settings', $field->name],
        ];
      }
    }
    $form['fields']['#type'] = 'container';
    $form['fields']['#attributes']['class'][] = 'crosspost-fields';
    if ($has_secret) {
      $form['fields']['no_key'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['crosspost-help']],
        '#value' => $this->t("A secret is kept in the Key module, not in the site's configuration: paste it as the key's value."),
      ];
    }

    $form['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['crosspost-actions']]];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect with @platform', ['@platform' => $adapter->label()]),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('crosspost.connections'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getErrors()) {
      return;
    }
    $adapter = $this->adapters->createInstance($form_state->getValue('adapter'));
    $settings = array_map('trim', (array) $form_state->getValue('settings', []));
    $keys = array_filter((array) $form_state->getValue('keys', []));
    try {
      $who = $adapter->identify($settings + $this->credentials->secrets($keys));
    }
    catch (AdapterException $e) {
      $form_state->setErrorByName('', $this->t('@platform did not accept this. Its words: %reply', [
        '@platform' => $adapter->label(),
        '%reply' => $e->getMessage(),
      ]));
      return;
    }
    $same = $this->entityTypeManager->getStorage('crosspost_connection')->loadByProperties([
      'adapter' => $adapter->getPluginId(),
      'label' => $who,
    ]);
    if ($same) {
      $form_state->setErrorByName('', $this->t('%who is already connected.', ['%who' => $who]));
      return;
    }
    $form_state->set('who', $who);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $adapter_id = $form_state->getValue('adapter');
    $who = (string) $form_state->get('who');
    $storage = $this->entityTypeManager->getStorage('crosspost_connection');
    $base = substr($adapter_id . '_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($who)), '_'), 0, 48);
    $id = $base;
    for ($i = 2; $storage->load($id); $i++) {
      $id = $base . '_' . $i;
    }
    $storage->create([
      'id' => $id,
      'label' => $who,
      'adapter' => $adapter_id,
      'settings' => array_map('trim', (array) $form_state->getValue('settings', [])),
      'keys' => array_filter((array) $form_state->getValue('keys', [])),
    ])->save();
    $this->messenger()->addStatus($this->t('Connected as %who.', ['%who' => $who]));
    $form_state->setRedirect('crosspost.connections');
  }

}
