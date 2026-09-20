<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\crosspost\Sharer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The settings: the public address, the content types, and trying again.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The sharer, for what a connection is called.
   */
  protected Sharer $sharer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->entityFieldManager = $container->get('entity_field.manager');
    $form->sharer = $container->get('crosspost.sharer');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'crosspost_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['crosspost.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('crosspost.settings');
    $form['#attached']['library'][] = 'crosspost/admin';

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Public address of the site'),
      '#default_value' => $config->get('base_url'),
      '#placeholder' => 'https://www.example.com',
      '#description' => $this->t('Where visitors see the site. On a static or decoupled site this is not the address of the CMS. Links in posts use it, and it is the address that is checked before anything is shared. Leave empty when visitors use the same address as this page.'),
    ];

    $connections = [];
    foreach ($this->entityTypeManager->getStorage('crosspost_connection')->loadMultiple() as $id => $connection) {
      $connections[$id] = $this->sharer->place($connection);
    }
    natcasesort($connections);

    $form['types_title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Content types')];
    $form['types_help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('A ticked type shows a Share tab beside Edit on each of its pages.'),
    ];
    $form['types'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Share tab'),
        $this->t('Content type'),
        $this->t('Hashtags from'),
        $this->t('Ticked to begin with'),
        $this->t('Once the page is public'),
      ],
      '#empty' => $this->t('There are no content types yet.'),
      '#attributes' => ['class' => ['crosspost-types']],
      '#prefix' => '<div class="crosspost-scroll">',
      '#suffix' => '</div>',
      '#responsive' => FALSE,
      '#sticky' => FALSE,
    ];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $bundle => $type) {
      $saved = $config->get('types.' . $bundle) ?: [];
      $on = [':input[name="types[' . $bundle . '][enabled]"]' => ['checked' => TRUE]];
      $form['types'][$bundle]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Share tab for @type', ['@type' => $type->label()]),
        '#title_display' => 'invisible',
        '#default_value' => !empty($saved['enabled']),
      ];
      $form['types'][$bundle]['label'] = ['#markup' => '<strong>' . $type->label() . '</strong>'];
      $form['types'][$bundle]['tag_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Hashtags from, for @type', ['@type' => $type->label()]),
        '#title_display' => 'invisible',
        '#options' => $this->tagFields($bundle),
        '#empty_option' => $this->t('None'),
        '#default_value' => $saved['tag_field'] ?? '',
        '#states' => ['visible' => $on],
      ];
      $form['types'][$bundle]['ticked'] = ['#type' => 'container', '#states' => ['visible' => $on]];
      $form['types'][$bundle]['ticked']['all_connections'] = [
        '#type' => 'select',
        '#title' => $this->t('Ticked to begin with, for @type', ['@type' => $type->label()]),
        '#title_display' => 'invisible',
        '#options' => [1 => $this->t('All connected accounts'), 0 => $this->t('Only these')],
        '#default_value' => (int) ($saved['all_connections'] ?? 1),
        '#parents' => ['types', $bundle, 'all_connections'],
      ];
      $form['types'][$bundle]['ticked']['connections'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Accounts for @type', ['@type' => $type->label()]),
        '#title_display' => 'invisible',
        '#options' => $connections,
        '#default_value' => array_intersect($saved['connections'] ?? [], array_keys($connections)),
        '#parents' => ['types', $bundle, 'connections'],
        '#states' => ['visible' => [':input[name="types[' . $bundle . '][all_connections]"]' => ['value' => '0']]],
      ];
      if (!$connections) {
        $form['types'][$bundle]['ticked']['connections'] = ['#markup' => ''];
      }
      $form['types'][$bundle]['auto'] = [
        '#type' => 'select',
        '#title' => $this->t('Once the page is public, for @type', ['@type' => $type->label()]),
        '#title_display' => 'invisible',
        '#options' => [0 => $this->t('Wait for someone to press Share'), 1 => $this->t('Share by itself')],
        '#default_value' => (int) !empty($saved['auto']),
        '#states' => ['visible' => $on],
      ];
    }
    $form['auto_help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['crosspost-help']],
      '#value' => $this->t('"Share by itself" posts the page\'s title and hashtags to the ticked accounts as soon as a newly published page\'s address answers. Words written on the Share tab before then are used instead.'),
    ];

    $form['again_title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Trying again')];
    $form['checking'] = ['#type' => 'container', '#attributes' => ['class' => ['crosspost-tries']]];
    $form['checking']['a'] = ['#markup' => '<span>' . $this->t('While a page is not public yet, check its address every') . '</span>'];
    $form['checking']['check_every'] = [
      '#type' => 'number',
      '#title' => $this->t('Minutes between checks'),
      '#title_display' => 'invisible',
      '#min' => 1,
      '#max' => 1440,
      '#default_value' => $config->get('check_every'),
    ];
    $form['checking']['b'] = ['#markup' => '<span>' . $this->t('minutes, for up to') . '</span>'];
    $form['checking']['check_for'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours to keep checking'),
      '#title_display' => 'invisible',
      '#min' => 1,
      '#max' => 720,
      '#default_value' => $config->get('check_for'),
    ];
    $form['checking']['c'] = ['#markup' => '<span>' . $this->t('hours.') . '</span>'];
    $form['retrying'] = ['#type' => 'container', '#attributes' => ['class' => ['crosspost-tries']]];
    $form['retrying']['a'] = ['#markup' => '<span>' . $this->t('When a platform cannot be reached, try') . '</span>'];
    $form['retrying']['retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Times to try again'),
      '#title_display' => 'invisible',
      '#min' => 0,
      '#max' => 10,
      '#default_value' => $config->get('retries'),
    ];
    $form['retrying']['b'] = ['#markup' => '<span>' . $this->t('more times. A refusal is never retried without someone asking.') . '</span>'];
    $form['cron_help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['crosspost-help']],
      '#value' => $this->t('Checks and retries happen when cron runs, or when a deploy pipeline runs <code>drush crosspost:share</code>.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $types = [];
    foreach ((array) $form_state->getValue('types') as $bundle => $values) {
      if (empty($values['enabled'])) {
        continue;
      }
      $types[$bundle] = [
        'enabled' => TRUE,
        'tag_field' => (string) ($values['tag_field'] ?? ''),
        'all_connections' => (bool) ($values['all_connections'] ?? TRUE),
        'connections' => array_values(array_filter((array) ($values['connections'] ?? []))),
        'auto' => (bool) ($values['auto'] ?? FALSE),
      ];
    }
    $this->config('crosspost.settings')
      ->set('base_url', rtrim((string) $form_state->getValue('base_url'), '/'))
      ->set('check_every', (int) $form_state->getValue('check_every'))
      ->set('check_for', (int) $form_state->getValue('check_for'))
      ->set('retries', (int) $form_state->getValue('retries'))
      ->set('types', $types)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * The fields of a content type that point at taxonomy terms.
   *
   * @return array<string, string>
   *   Field labels keyed by field name.
   */
  protected function tagFields(string $bundle): array {
    $fields = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
      if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'taxonomy_term') {
        $fields[$name] = (string) $definition->getLabel();
      }
    }
    return $fields;
  }

}
