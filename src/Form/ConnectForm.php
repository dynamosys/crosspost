<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;
use Drupal\crosspost\Credentials;
use Drupal\crosspost\OAuthFlow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A platform's setup guide, then the fields to connect one account.
 */
class ConnectForm extends FormBase {

  public function __construct(
    protected AdapterPluginManager $adapters,
    protected Credentials $credentials,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OAuthFlow $flow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.crosspost_adapter'),
      $container->get('crosspost.credentials'),
      $container->get('entity_type.manager'),
      $container->get('crosspost.oauth_flow'),
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
    $oauth = $adapter instanceof OAuthAdapterInterface;
    $form['guide'] = [
      '#theme' => 'crosspost_guide',
      '#platform' => $adapter->label(),
      '#name' => $adapter->platformName(),
      '#guide' => $guide,
      '#step' => $oauth ? 1 : 0,
    ];
    // An app that is already in use for this platform is offered again, so
    // adding another account means pressing one button.
    $known = [];
    if ($oauth) {
      $connections = $this->entityTypeManager->getStorage('crosspost_connection')->loadByProperties(['adapter' => $adapter_id]);
      if ($connection = reset($connections)) {
        $known = ['settings' => $connection->getSettings(), 'keys' => $connection->getKeys()];
      }
      $form['redirect'] = [
        '#type' => 'textfield',
        '#title' => $this->t("Redirect address, to paste into the platform's app"),
        '#value' => $this->flow->redirectUri($adapter_id),
        '#attributes' => ['readonly' => 'readonly', 'class' => ['crosspost-readonly']],
        '#wrapper_attributes' => ['class' => ['crosspost-fields']],
      ];
    }

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
          '#default_value' => $known['keys'][$field->name] ?? NULL,
          '#parents' => ['keys', $field->name],
        ];
      }
      else {
        $form['fields'][$field->name] = [
          '#type' => 'textfield',
          '#title' => $field->label,
          '#description' => $field->description,
          '#default_value' => $known['settings'][$field->name] ?? $field->default,
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
      '#value' => $oauth
        ? $this->t('Continue with @platform', ['@platform' => $adapter->platformName()])
        : $this->t('Connect with @platform', ['@platform' => $adapter->platformName()]),
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
    if ($adapter instanceof OAuthAdapterInterface) {
      // The platform itself says who this is, after the person approves.
      return;
    }
    $settings = array_map('trim', (array) $form_state->getValue('settings', []));
    $keys = array_filter((array) $form_state->getValue('keys', []));
    try {
      $who = $adapter->identify($settings + $this->credentials->secrets($keys));
    }
    catch (AdapterException $e) {
      $form_state->setErrorByName('', $this->t('@platform did not accept this. Its words: %reply', [
        '@platform' => $adapter->platformName(),
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
    if ($this->adapters->createInstance($adapter_id) instanceof OAuthAdapterInterface) {
      $settings = array_map('trim', (array) $form_state->getValue('settings', []));
      $keys = array_filter((array) $form_state->getValue('keys', []));
      $form_state->setResponse(new TrustedRedirectResponse($this->flow->begin($adapter_id, $settings, $keys)));
      return;
    }
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
