<?php

declare(strict_types=1);

namespace Drupal\crosspost;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\crosspost\Entity\AnnouncementInterface;

/**
 * Builds the log table: where content went, and what each platform said.
 */
class LogBuilder {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
    protected RedirectDestinationInterface $destination,
  ) {}

  /**
   * The states, as the log names them.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by state.
   */
  public function states(): array {
    return [
      AnnouncementInterface::POSTED => $this->t('Posted'),
      AnnouncementInterface::WAITING => $this->t('Waiting'),
      AnnouncementInterface::REFUSED => $this->t('Refused'),
      AnnouncementInterface::FAILED => $this->t('Failed'),
      AnnouncementInterface::CANCELLED => $this->t('Cancelled'),
    ];
  }

  /**
   * Builds the table.
   *
   * @param \Drupal\crosspost\Entity\AnnouncementInterface[] $announcements
   *   The rows.
   * @param bool $with_content
   *   Whether to show which content each row belongs to.
   */
  public function table(array $announcements, bool $with_content = FALSE): array {
    $states = $this->states();
    $pills = [
      AnnouncementInterface::POSTED => 'ok',
      AnnouncementInterface::WAITING => 'wait',
      AnnouncementInterface::REFUSED => 'err',
      AnnouncementInterface::FAILED => 'err',
      AnnouncementInterface::CANCELLED => 'off',
    ];
    $rows = [];
    foreach ($announcements as $announcement) {
      $state = $announcement->getState();
      $when = (int) ($announcement->get('posted')->value ?: $announcement->get('changed')->value);
      $said = ['reply' => ['#plain_text' => (string) $announcement->get('reply')->value]];
      if ($state === AnnouncementInterface::REFUSED || $state === AnnouncementInterface::FAILED) {
        $said['reply'] = [
          '#type' => 'inline_template',
          '#template' => '{% if status %}{{ "Answered @status."|t({"@status": status}) }} {% endif %}{{ "Its words:"|t }} <span class="crosspost-reply">{{ reply }}</span> {{ "Nothing was posted."|t }}',
          '#context' => [
            'status' => $announcement->get('http_status')->value,
            'reply' => (string) $announcement->get('reply')->value,
          ],
        ];
      }
      if ($announcement->get('remote_url')->value) {
        $said['link'] = [
          '#type' => 'link',
          '#title' => $this->t('See it there'),
          '#url' => Url::fromUri($announcement->get('remote_url')->value),
          '#prefix' => ' ',
          '#attributes' => ['rel' => 'noopener'],
        ];
      }
      $links = [];
      $options = ['query' => $this->destination->getAsArray()];
      if ($state === AnnouncementInterface::WAITING) {
        $links['try'] = [
          'title' => $this->t('Try now'),
          'url' => Url::fromRoute('crosspost.announcement.act', [
            'crosspost_announcement' => $announcement->id(),
            'operation' => 'try',
          ], $options),
        ];
        $links['cancel'] = [
          'title' => $this->t('Cancel'),
          'url' => Url::fromRoute('crosspost.announcement.act', [
            'crosspost_announcement' => $announcement->id(),
            'operation' => 'cancel',
          ], $options),
        ];
      }
      elseif (in_array($state, [
        AnnouncementInterface::REFUSED,
        AnnouncementInterface::FAILED,
        AnnouncementInterface::CANCELLED,
      ], TRUE)) {
        $links['try'] = [
          'title' => $this->t('Try again'),
          'url' => Url::fromRoute('crosspost.announcement.act', [
            'crosspost_announcement' => $announcement->id(),
            'operation' => 'try',
          ], $options),
        ];
      }
      $row = [];
      if ($with_content) {
        $node = $announcement->getNode();
        $row[] = $node ? [
          'data' => [
            '#type' => 'link',
            '#title' => $node->label(),
            '#url' => Url::fromRoute('crosspost.share', ['node' => $node->id()]),
          ],
        ] : $this->t('Deleted content');
      }
      $row[] = ['data' => ['#markup' => '<strong>' . htmlspecialchars((string) $announcement->get('place')->value) . '</strong>']];
      $row[] = [
        'data' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $states[$state] ?? $state,
          '#attributes' => ['class' => ['crosspost-pill', 'crosspost-pill--' . ($pills[$state] ?? 'off')]],
        ],
      ];
      $row[] = $state === AnnouncementInterface::WAITING ? '—' : $this->dateFormatter->format($when, 'short');
      $row[] = ['data' => $said];
      $row[] = $links ? ['data' => ['#type' => 'operations', '#links' => $links]] : '—';
      $rows[] = $row;
    }
    $header = [
      $this->t('Where'),
      $this->t('Result'),
      $this->t('When'),
      $this->t('What the platform said'),
      $this->t('Operations'),
    ];
    if ($with_content) {
      array_unshift($header, $this->t('Content'));
    }
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('Nothing has been shared yet.'),
      '#attributes' => ['class' => ['crosspost-log']],
      '#prefix' => '<div class="crosspost-scroll">',
      '#suffix' => '</div>',
      '#responsive' => FALSE,
      '#sticky' => FALSE,
      '#attached' => ['library' => ['crosspost/admin']],
    ];
  }

}
