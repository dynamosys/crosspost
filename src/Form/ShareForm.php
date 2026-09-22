<?php

declare(strict_types=1);

namespace Drupal\crosspost\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\AutoShare;
use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\crosspost\LogBuilder;
use Drupal\crosspost\PublicPage;
use Drupal\crosspost\Sharer;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Share tab: the words once, the accounts, and where it went.
 */
class ShareForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AdapterPluginManager $adapters,
    protected PublicPage $publicPage,
    protected Sharer $sharer,
    protected AutoShare $autoShare,
    protected LogBuilder $logBuilder,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.crosspost_adapter'),
      $container->get('crosspost.public_page'),
      $container->get('crosspost.sharer'),
      $container->get('crosspost.auto_share'),
      $container->get('crosspost.log_builder'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'crosspost_share';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form_state->set('node', $node->id());
    $check = $this->publicPage->check($node);
    $form_state->set('public', $check->isPublic());
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $announcements = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('node', $node->id())->sort('id', 'DESC')->execute());
    $draft = NULL;
    $taken = [];
    foreach ($announcements as $id => $announcement) {
      if ($announcement->getState() === AnnouncementInterface::DRAFT) {
        $draft ??= $announcement;
        unset($announcements[$id]);
      }
      elseif (in_array($announcement->getState(), [AnnouncementInterface::WAITING, AnnouncementInterface::POSTED], TRUE)) {
        $taken[$announcement->getConnectionId()] = $announcement->getState();
      }
    }

    $form['#attached']['library'][] = 'crosspost/share';
    if ($check->isPublic()) {
      $form['public'] = $this->note('status', $this->t('<strong>The page is public.</strong> <a href=":url">Its address</a> answered at @time.', [
        ':url' => $check->url,
        '@time' => $this->dateFormatter->format(time(), 'custom', 'H:i'),
      ]));
    }
    else {
      $answer = $check->status ? $this->t('answered @status', ['@status' => $check->status]) : $this->t('did not answer');
      $form['public'] = $this->note('warning', $this->t('<strong>The page is not public yet.</strong> <a href=":url">Its address</a> @answer at @time. Anything you share now waits, and goes out once the address answers.', [
        ':url' => $check->url,
        '@answer' => $answer,
        '@time' => $this->dateFormatter->format(time(), 'custom', 'H:i'),
      ]));
    }

    $connections = $this->entityTypeManager->getStorage('crosspost_connection')->loadMultiple();
    if (!$connections) {
      $form['none'] = $this->note('warning', $this->t('No account is connected yet. <a href=":url">Connect one</a> first.', [':url' => Url::fromRoute('crosspost.connections')->toString()]));
    }

    $options = $limits = [];
    foreach ($connections as $id => $connection) {
      if (!$this->adapters->hasDefinition($connection->getAdapterId())) {
        continue;
      }
      $adapter = $this->adapters->createInstance($connection->getAdapterId());
      $options[$id] = $this->sharer->place($connection);
      $limit = $adapter->limits();
      $limits[$id] = ['label' => $options[$id], 'max' => $limit->textLength, 'link' => $limit->linkLength];
    }
    natcasesort($options);
    $form_state->set('places', $options);

    $form['grid'] = ['#type' => 'container', '#attributes' => ['class' => ['crosspost-grid']]];
    $form['grid']['words'] = ['#type' => 'container'];
    $form['grid']['words']['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What to say'),
      '#default_value' => $draft ? $draft->getText() : $this->autoShare->defaultText($node),
      '#rows' => 5,
      '#required' => TRUE,
      '#description' => $this->t("The link is added for you, in the text or in the card, as each platform wants it. Hashtags come from the page's tags; remove any you do not want."),
      '#attributes' => ['data-crosspost-text' => ''],
    ];
    $form['grid']['words']['counts'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['crosspost-counts'],
        'data-crosspost-counts' => '',
        'aria-live' => 'polite',
        'aria-label' => $this->t('Characters used for each account'),
      ],
    ];
    if ($options) {
      $form['grid']['words']['overrides'] = [
        '#type' => 'details',
        '#title' => $this->t('Different words for one account'),
        '#tree' => TRUE,
      ];
      $form['grid']['words']['overrides']['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['crosspost-help']],
        '#value' => $this->t('Leave empty to use the words above.'),
      ];
      foreach ($options as $id => $label) {
        $form['grid']['words']['overrides'][$id] = [
          '#type' => 'textarea',
          '#title' => $label,
          '#rows' => 3,
          '#attributes' => ['data-crosspost-override' => $id],
        ];
      }
    }

    $form['grid']['where'] = ['#type' => 'container'];
    $form['grid']['where']['connections'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Where'),
      '#options' => $options,
      '#default_value' => array_keys(array_diff_key(array_intersect_key($this->autoShare->defaultConnections($node->bundle()), $options), $taken)),
    ];
    foreach ($taken as $id => $state) {
      if (isset($options[$id])) {
        $form['grid']['where']['connections'][$id]['#disabled'] = TRUE;
        $form['grid']['where']['connections'][$id]['#description'] = $state === AnnouncementInterface::POSTED ? $this->t('Already posted there.') : $this->t('Already waiting to go there.');
      }
    }
    $form['grid']['where']['card_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h4',
      '#value' => $this->t('How the link will look'),
    ];
    $form['grid']['where']['card'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="crosspost-card">{% if image %}<img src="{{ image }}" alt="">{% endif %}<div class="crosspost-card__text"><strong>{{ title }}</strong>{% if summary %}<small>{{ summary }}</small>{% endif %}<small>{{ host }}</small></div></div>{% if not public %}<p class="crosspost-help">{{ "The picture and description are read from the page once it is public."|t }}</p>{% endif %}',
      '#context' => [
        'image' => $check->image,
        'title' => $check->title ?: $node->label(),
        'summary' => $check->summary,
        'host' => parse_url($check->url, PHP_URL_HOST),
        'public' => $check->isPublic(),
      ],
    ];
    $form['#attached']['drupalSettings']['crosspost'] = ['url' => $check->url, 'limits' => $limits];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['share'] = [
      '#type' => 'submit',
      '#value' => $check->isPublic() ? $this->t('Share now') : $this->t('Share when the page is public'),
      '#button_type' => 'primary',
      '#disabled' => !array_diff_key($options, $taken),
    ];
    $form['actions']['draft'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save as draft'),
      '#submit' => ['::saveDraft'],
      '#limit_validation_errors' => [['text']],
    ];

    if ($announcements) {
      $form['log_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Where this page went'),
        '#weight' => 110,
      ];
      $form['log'] = $this->logBuilder->table($announcements) + ['#weight' => 111];
      $form['log_help'] = [
        '#weight' => 112,
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['crosspost-help']],
        '#value' => $this->t('A page is sent to each account once. "Try again" is offered only after a refusal or a failure.'),
      ];
    }
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getTriggeringElement()['#parents'] !== ['share']) {
      return;
    }
    $picked = array_filter((array) $form_state->getValue('connections'));
    if (!$picked) {
      $form_state->setErrorByName('connections', $this->t('Tick at least one account.'));
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($form_state->get('node'));
    $url = $this->publicPage->url($node);
    $connections = $this->entityTypeManager->getStorage('crosspost_connection')->loadMultiple($picked);
    foreach ($connections as $id => $connection) {
      $limits = $this->adapters->createInstance($connection->getAdapterId())->limits();
      $used = $limits->used($this->textFor($form_state, $id), $url);
      if ($used > $limits->textLength) {
        $form_state->setErrorByName('text', $this->t('@place takes @max characters, the link included. This is @used. Shorten the words, or write shorter ones for that account.', [
          '@place' => $form_state->get('places')[$id],
          '@max' => $limits->textLength,
          '@used' => $used,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->entityTypeManager->getStorage('node')->load($form_state->get('node'));
    $picked = array_filter((array) $form_state->getValue('connections'));
    $queued = [];
    foreach ($this->entityTypeManager->getStorage('crosspost_connection')->loadMultiple($picked) as $id => $connection) {
      if ($announcement = $this->sharer->queue($node, $connection, $this->textFor($form_state, $id))) {
        $queued[] = $announcement;
      }
    }
    $this->deleteDrafts((int) $node->id());
    $checks = [];
    foreach ($queued as $announcement) {
      $after = $this->sharer->process($announcement, $checks);
      $words = ['@place' => $after->get('place')->value, '%reply' => $after->get('reply')->value];
      match ($after->getState()) {
        AnnouncementInterface::POSTED => $this->messenger()->addStatus($this->t('Posted to @place.', $words)),
        AnnouncementInterface::WAITING => $this->messenger()->addWarning($this->t('@place: waiting. %reply', $words)),
        default => $this->messenger()->addError($this->t('@place: nothing was posted. %reply', $words)),
      };
    }
  }

  /**
   * Keeps the words for later without sharing anything.
   */
  public function saveDraft(array &$form, FormStateInterface $form_state): void {
    $node_id = (int) $form_state->get('node');
    $this->deleteDrafts($node_id);
    $this->entityTypeManager->getStorage('crosspost_announcement')->create([
      'node' => $node_id,
      'text' => trim((string) $form_state->getValue('text')),
      'state' => AnnouncementInterface::DRAFT,
      'uid' => $this->currentUser()->id(),
    ])->save();
    $this->messenger()->addStatus($this->t('Draft saved. Nothing was shared.'));
  }

  /**
   * The words for one connection: its own, or the ones for everyone.
   */
  protected function textFor(FormStateInterface $form_state, string $connection_id): string {
    $own = trim((string) ($form_state->getValue(['overrides', $connection_id]) ?? ''));
    return $own !== '' ? $own : trim((string) $form_state->getValue('text'));
  }

  /**
   * Deletes a page's drafts.
   */
  protected function deleteDrafts(int $node_id): void {
    $storage = $this->entityTypeManager->getStorage('crosspost_announcement');
    $storage->delete($storage->loadByProperties(['node' => $node_id, 'state' => AnnouncementInterface::DRAFT]));
  }

  /**
   * A message box inside the form.
   */
  protected function note(string $type, string|\Stringable $text): array {
    return [
      '#theme' => 'status_messages',
      '#message_list' => [$type => [$text]],
      '#status_headings' => ['status' => $this->t('Status message'), 'warning' => $this->t('Warning message')],
    ];
  }

}
