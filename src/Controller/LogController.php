<?php

declare(strict_types=1);

namespace Drupal\crosspost\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\crosspost\Entity\AnnouncementInterface;
use Drupal\crosspost\Form\LogFilterForm;
use Drupal\crosspost\LogBuilder;
use Drupal\crosspost\Sharer;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The site-wide log, and the operations on its rows.
 */
class LogController extends ControllerBase {

  public function __construct(
    protected LogBuilder $logBuilder,
    protected Sharer $sharer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('crosspost.log_builder'), $container->get('crosspost.sharer'));
  }

  /**
   * The log for the whole site.
   */
  public function page(Request $request): array {
    $storage = $this->entityTypeManager()->getStorage('crosspost_announcement');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', AnnouncementInterface::DRAFT, '<>')
      ->sort('changed', 'DESC')
      ->pager(50);
    $states = $this->logBuilder->states();
    if (($state = (string) $request->query->get('state', '')) && isset($states[$state])) {
      $query->condition('state', $state);
    }
    $places = [];
    foreach ($this->entityTypeManager()->getStorage('crosspost_connection')->loadMultiple() as $id => $connection) {
      $places[$id] = $this->sharer->place($connection);
    }
    natcasesort($places);
    if (($connection = (string) $request->query->get('connection', '')) && isset($places[$connection])) {
      $query->condition('connection', $connection);
    }
    return [
      'filters' => $this->formBuilder()->getForm(LogFilterForm::class, $states, $places),
      'table' => $this->logBuilder->table($storage->loadMultiple($query->execute()), TRUE),
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The Share tab's title: the content's own.
   */
  public function shareTitle(NodeInterface $node): string {
    return (string) $node->label();
  }

  /**
   * Try now, try again, or cancel.
   */
  public function act(AnnouncementInterface $crosspost_announcement, string $operation, Request $request): RedirectResponse {
    if ($operation === 'cancel') {
      $this->sharer->cancel($crosspost_announcement);
      $this->messenger()->addStatus($this->t('Cancelled. Nothing went to @place.', ['@place' => $crosspost_announcement->get('place')->value]));
    }
    else {
      if ($crosspost_announcement->getState() !== AnnouncementInterface::WAITING && $crosspost_announcement->getState() !== AnnouncementInterface::POSTED) {
        $this->sharer->requeue($crosspost_announcement);
      }
      $after = $this->sharer->process($crosspost_announcement);
      $words = ['@place' => $after->get('place')->value, '%reply' => $after->get('reply')->value];
      match ($after->getState()) {
        AnnouncementInterface::POSTED => $this->messenger()->addStatus($this->t('Posted to @place.', $words)),
        AnnouncementInterface::WAITING => $this->messenger()->addWarning($this->t('@place: still waiting. %reply', $words)),
        default => $this->messenger()->addError($this->t('@place: nothing was posted. %reply', $words)),
      };
    }
    $node = $crosspost_announcement->getNode();
    $fallback = $node ? Url::fromRoute('crosspost.share', ['node' => $node->id()]) : Url::fromRoute('crosspost.log');
    // The destination query, when there is one, wins over this by itself.
    return new RedirectResponse($fallback->toString());
  }

}
