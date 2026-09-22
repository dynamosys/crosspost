<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\OAuthFlow;
use Drupal\crosspost\Sharer;
use Drupal\crosspost\TokenStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Back from the platform: choose which accounts or pages to connect.
 */
class ChooseAccountsForm extends FormBase {

  public function __construct(
    protected AdapterPluginManager $adapters,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OAuthFlow $flow,
    protected TokenStore $tokens,
    protected StateInterface $problems,
    protected Sharer $sharer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.crosspost_adapter'),
      $container->get('entity_type.manager'),
      $container->get('crosspost.oauth_flow'),
      $container->get('crosspost.tokens'),
      $container->get('state'),
      $container->get('crosspost.sharer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'crosspost_choose_accounts';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $adapter_id = ''): array {
    $adapter = $this->adapters->createInstance($adapter_id);
    $pending = $this->flow->pending($adapter_id);
    /** @var \Drupal\crosspost\Adapter\Approval $approval */
    $approval = $pending['approval'];
    $noun = $adapter->accountNoun();
    $form['#attributes']['id'] = 'crosspost-guide';
    $form['#attributes']['class'][] = 'crosspost-guide';
    $form['adapter'] = ['#type' => 'value', '#value' => $adapter_id];
    $form['guide'] = [
      '#theme' => 'crosspost_guide',
      '#platform' => $adapter->label(),
      '#name' => $adapter->platformName(),
      '#guide' => $adapter->guide(),
      '#step' => 3,
      '#facts' => FALSE,
    ];
    $form['who'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('<strong>@platform approved the app for @person.</strong> Choose what this site may post to. Nothing is posted now.', [
        '@platform' => $adapter->platformName(),
        '@person' => $approval->person,
      ]),
    ];

    $connected = [];
    foreach ($this->entityTypeManager->getStorage('crosspost_connection')->loadByProperties(['adapter' => $adapter_id]) as $connection) {
      $connected[$connection->getSettings()['account_id'] ?? ''] = $connection->id();
    }
    $reconnect = $pending['reconnect'] ?? NULL;
    $options = [];
    foreach ($approval->accounts as $account) {
      $options[$account->id] = $account->label;
    }
    $form['accounts'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Accounts'),
      '#title_display' => 'invisible',
      '#options' => $options,
      '#attributes' => ['class' => ['crosspost-accounts']],
    ];
    foreach ($approval->accounts as $account) {
      $again = isset($connected[$account->id]) && $connected[$account->id] === $reconnect;
      $form['accounts'][$account->id]['#description'] = $account->note;
      if (!$account->connectable) {
        $form['accounts'][$account->id]['#disabled'] = TRUE;
      }
      elseif ($again) {
        $form['accounts'][$account->id]['#default_value'] = $account->id;
        $form['accounts'][$account->id]['#description'] = $this->t('Connected before. Ticked, so its access is renewed.');
      }
      elseif (isset($connected[$account->id])) {
        $form['accounts'][$account->id]['#description'] = $this->t('Already connected. Tick it to renew its access.');
      }
    }
    if (!$options) {
      $form['none'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('@platform returned nothing to connect. On its approval screen, choose at least one @noun.', [
          '@platform' => $adapter->platformName(),
          '@noun' => $noun,
        ]),
      ];
      if ($approval->granted) {
        $form['granted'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['crosspost-help']],
          '#value' => $this->t('What @platform says it granted: @list', [
            '@platform' => $adapter->platformName(),
            '@list' => implode(', ', $approval->granted),
          ]),
        ];
      }
    }
    $form['missing'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['crosspost-help']],
      '#value' => $this->t("Something missing from this list was not chosen on @platform's approval screen. Press Start again and choose it there.", ['@platform' => $adapter->platformName()]),
    ];

    $form['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['crosspost-actions']]];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect what is ticked'),
      '#button_type' => 'primary',
      '#disabled' => !$options,
    ];
    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start again'),
      '#submit' => ['::startAgain'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!array_filter((array) $form_state->getValue('accounts'))) {
      $form_state->setErrorByName('accounts', $this->t('Tick at least one.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $adapter_id = $form_state->getValue('adapter');
    $pending = $this->flow->pending($adapter_id);
    $picked = array_filter((array) $form_state->getValue('accounts'));
    $storage = $this->entityTypeManager->getStorage('crosspost_connection');
    $existing = [];
    foreach ($storage->loadByProperties(['adapter' => $adapter_id]) as $connection) {
      $existing[$connection->getSettings()['account_id'] ?? ''] = $connection;
    }
    foreach ($pending['approval']->accounts as $account) {
      if (!isset($picked[$account->id]) || !$account->connectable) {
        continue;
      }
      $settings = $pending['settings'] + $account->settings + ['account_id' => $account->id];
      if ($connection = $existing[$account->id] ?? NULL) {
        $connection->set('label', $account->label)->set('settings', $settings)->set('keys', $pending['keys'])->save();
        $this->messenger()->addStatus($this->t('Access renewed for %who.', ['%who' => $account->label]));
      }
      else {
        $base = substr($adapter_id . '_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($account->label)), '_'), 0, 48);
        $id = $base;
        for ($i = 2; $storage->load($id); $i++) {
          $id = $base . '_' . $i;
        }
        $connection = $storage->create([
          'id' => $id,
          'label' => $account->label,
          'adapter' => $adapter_id,
          'settings' => $settings,
          'keys' => $pending['keys'],
        ]);
        $connection->save();
        $this->messenger()->addStatus($this->t('Connected as %who.', ['%who' => $account->label]));
      }
      $this->tokens->set((string) $connection->id(), $account->tokens);
      $this->problems->delete('crosspost.problem.' . $connection->id());
      if ($again = $this->sharer->requeueAccessLost((string) $connection->id())) {
        $this->messenger()->addStatus($this->formatPlural($again, '1 post that was refused for lack of access is in line again.', '@count posts that were refused for lack of access are in line again.'));
      }
    }
    $this->flow->clear($adapter_id);
    $form_state->setRedirect('crosspost.connections');
  }

  /**
   * Drops what came back and shows the guide's first step again.
   */
  public function startAgain(array &$form, FormStateInterface $form_state): void {
    $adapter_id = $form_state->getValue('adapter') ?: $form['adapter']['#value'];
    $this->flow->clear($adapter_id);
    $form_state->setRedirectUrl(Url::fromRoute('crosspost.connections', [], [
      'query' => ['guide' => $adapter_id],
      'fragment' => 'crosspost-guide',
    ]));
  }

}
