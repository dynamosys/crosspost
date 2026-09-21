<?php

declare(strict_types=1);

namespace Drupal\crosspost\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\crosspost\Adapter\AdapterException;
use Drupal\crosspost\Adapter\AdapterPluginManager;
use Drupal\crosspost\Adapter\OAuthAdapterInterface;
use Drupal\crosspost\Credentials;
use Drupal\crosspost\Entity\ConnectionInterface;
use Drupal\crosspost\Form\ChooseAccountsForm;
use Drupal\crosspost\Form\ConnectForm;
use Drupal\crosspost\OAuthFlow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Connections page: every installed platform, every connected account.
 */
class ConnectionsController extends ControllerBase {

  public function __construct(
    protected AdapterPluginManager $adapters,
    protected Credentials $credentials,
    protected StateInterface $problems,
    protected DateFormatterInterface $dateFormatter,
    protected OAuthFlow $flow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.crosspost_adapter'),
      $container->get('crosspost.credentials'),
      $container->get('state'),
      $container->get('date.formatter'),
      $container->get('crosspost.oauth_flow'),
    );
  }

  /**
   * Builds the page.
   */
  public function page(Request $request): array {
    $open = (string) $request->query->get('guide', '');
    $by_adapter = [];
    foreach ($this->entityTypeManager()->getStorage('crosspost_connection')->loadMultiple() as $connection) {
      $by_adapter[$connection->getAdapterId()][$connection->id()] = $connection;
    }
    $last = $this->lastPosts();

    $rows = [];
    // Definitions come sorted by label: the page ranks no platform.
    foreach ($this->adapters->getDefinitions() as $id => $definition) {
      $adapter = $this->adapters->createInstance($id);
      $connections = $by_adapter[$id] ?? [];
      uasort($connections, static fn(ConnectionInterface $a, ConnectionInterface $b): int => strnatcasecmp((string) $a->label(), (string) $b->label()));
      $is_open = $open === $id;
      $toggle = $is_open
        ? ['#type' => 'link', '#title' => $this->t('Close guide'), '#url' => Url::fromRoute('crosspost.connections')]
        : [
          '#type' => 'link',
          '#title' => $connections ? $this->t('Add another @noun', ['@noun' => $adapter->accountNoun()]) : $this->t('Set up'),
          '#url' => Url::fromRoute('crosspost.connections', [], [
            'query' => ['guide' => $id],
            'fragment' => 'crosspost-guide',
          ]),
        ];

      if (!$connections) {
        $toggle['#attributes']['class'] = ['button', 'button--small'];
        $rows[] = [
          'class' => $is_open ? ['crosspost-open'] : [],
          'data' => [
            ['data' => ['#markup' => '<strong>' . $adapter->label() . '</strong>']],
            ['data' => $this->pill('off', $this->t('Not connected'))],
            '—',
            '—',
            ['data' => $toggle],
          ],
        ];
      }
      $first = TRUE;
      foreach ($connections as $connection) {
        $problem = (string) $this->problems->get('crosspost.problem.' . $connection->id(), '');
        $cells = [];
        if ($first) {
          $toggle['#attributes']['class'] = ['crosspost-add'];
          $cells[] = [
            'rowspan' => count($connections),
            'data' => ['name' => ['#markup' => '<strong>' . $adapter->label() . '</strong><br>'], 'add' => $toggle],
          ];
        }
        $cells[] = ['data' => $problem ? $this->pill('err', $this->t('Needs attention')) : $this->pill('ok', $this->t('Connected'))];
        $cells[] = [
          'data' => [
            'label' => ['#plain_text' => (string) $connection->label()],
            'problem' => $problem ? [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#value' => $problem,
              '#attributes' => ['class' => ['crosspost-help']],
            ] : [],
          ],
        ];
        $cells[] = isset($last[$connection->id()]) ? $this->dateFormatter->format($last[$connection->id()], 'short') : '—';
        $cells[] = [
          'data' => [
            '#type' => 'operations',
            '#links' => ($problem && $adapter instanceof OAuthAdapterInterface ? [
              'reconnect' => [
                'title' => $this->t('Reconnect'),
                'url' => Url::fromRoute('crosspost.connection.reconnect', ['crosspost_connection' => $connection->id()]),
              ],
            ] : []) + [
              'test' => [
                'title' => $this->t('Test'),
                'url' => Url::fromRoute('crosspost.connection.test', ['crosspost_connection' => $connection->id()]),
              ],
              'disconnect' => ['title' => $this->t('Disconnect'), 'url' => $connection->toUrl('delete-form')],
            ],
          ],
        ];
        $rows[] = ['class' => $is_open && $first ? ['crosspost-open'] : [], 'data' => $cells];
        $first = FALSE;
      }
      if ($is_open) {
        $rows[] = [
          'class' => ['crosspost-guide-row'],
          'data' => [
            [
              'colspan' => 5,
              'data' => $this->formBuilder()->getForm($this->backFromPlatform($id) ? ChooseAccountsForm::class : ConnectForm::class, $id),
            ],
          ],
        ];
      }
    }

    return [
      '#attached' => ['library' => ['crosspost/admin']],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Connect the accounts this site may post to. Whether to connect a platform, and whether to pay for one that charges, is your decision. The module only carries the post.'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Platform'),
          $this->t('State'),
          $this->t('Account'),
          $this->t('Last post'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No platform adapters are installed.'),
        '#attributes' => ['class' => ['crosspost-connections']],
        '#prefix' => '<div class="crosspost-scroll">',
        '#suffix' => '</div>',
        '#responsive' => FALSE,
        '#sticky' => FALSE,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Asks the platform who a connection's credentials belong to.
   */
  public function test(ConnectionInterface $crosspost_connection): RedirectResponse {
    $adapter = $this->adapters->createInstance($crosspost_connection->getAdapterId());
    try {
      $who = $adapter->identify($this->credentials->resolve($crosspost_connection));
      $this->problems->delete('crosspost.problem.' . $crosspost_connection->id());
      $this->messenger()->addStatus($this->t('@platform answered: these keys belong to %who. Nothing was posted.', [
        '@platform' => $adapter->platformName(),
        '%who' => $who,
      ]));
    }
    catch (AdapterException $e) {
      $this->problems->set('crosspost.problem.' . $crosspost_connection->id(), $e->getMessage());
      $this->messenger()->addError($this->t('@platform did not accept the keys. Its words: %reply', [
        '@platform' => $adapter->platformName(),
        '%reply' => $e->getMessage(),
      ]));
    }
    return new RedirectResponse(Url::fromRoute('crosspost.connections')->toString());
  }

  /**
   * Whether the person has just come back from a platform with an approval.
   */
  protected function backFromPlatform(string $adapter_id): bool {
    return !empty($this->flow->pending($adapter_id)['approval']);
  }

  /**
   * A state pill.
   */
  protected function pill(string $kind, string|\Stringable $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $text,
      '#attributes' => ['class' => ['crosspost-pill', 'crosspost-pill--' . $kind]],
    ];
  }

  /**
   * When each connection last posted.
   *
   * @return array<string, int>
   *   Timestamps keyed by connection ID.
   */
  protected function lastPosts(): array {
    $query = $this->entityTypeManager()->getStorage('crosspost_announcement')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'posted')
      ->groupBy('connection')
      ->aggregate('posted', 'MAX');
    $last = [];
    foreach ($query->execute() as $row) {
      $last[$row['connection']] = (int) $row['posted_max'];
    }
    return $last;
  }

}
