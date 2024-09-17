<?php

namespace Drupal\qa\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\qa\BasePackage;
use Drupal\qa\Exportable;
use Drupal\qa\Plugin\Qa\Control\BaseControl;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a QA form.
 */
class ReportForm extends FormBase {

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $translation;

  public function __construct(
    ModuleHandlerInterface $mh,
    TranslationInterface $translation
  ) {
    $this->moduleHandler = $mh;
    $this->translation = $translation;
  }

  public static function create(ContainerInterface $container) {
    $mh = $container->get('module_handler');
    $translation = $container->get('string_translation');
    return new static($mh, $translation);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'qa_report';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $packages = Exportable::getClasses(__DIR__ . "/../..", BasePackage::class);
    ksort($packages);

    $session = $this->getRequest()->getSession();
    foreach ($packages as $package_name => $package) {
      $open = FALSE;
      $form[$package_name] = array(
        '#type' => 'details',
        '#title' => Xss::filterAdmin($package->title),
        '#description' => Xss::filterAdmin($package->description),
        '#collapsible' => TRUE,
      );
      $controls = $package->getClasses($package->dir, BaseControl::class);
      foreach ($controls as $control_name => $control) {
        $default_value = $session->get($control_name);
        if ($default_value) {
          $open = TRUE;
        }
        $deps = array();
        $met = TRUE;
        foreach ($control->getDependencies() as $dep_name) {
          if ($this->moduleHandler->moduleExists($dep_name)) {
            $deps[] = $this->t('@module (<span class="admin-enabled">available</span>)', [
              '@module' => $dep_name,
            ]);
          }
          else {
            $deps[] = $this->t('@module (<span class="admin-disabled">unavailable</span>)', [
              '@module' => $dep_name,
            ]);
            $met = FALSE;
          }
        }
        $form[$package_name][$control_name] = [
          '#type'          => 'checkbox',
          '#default_value' => $met ? $default_value : 0,
          '#title'         => Xss::filterAdmin($control->title),
          '#description'   => Xss::filterAdmin($control->description),
          '#disabled'      => !$met,
        ];
        $form[$package_name][$control_name .'-dependencies'] = [
          '#value' => $this->t('Depends on: !dependencies', [
            '!dependencies' => implode(', ', $deps),
          ]),
          '#prefix' => '<div class="admin-dependencies">',
          '#suffix' => '</div>',
        ];
      }
      $form[$package_name]['#open'] = $open;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run checked controls'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
//    if (mb_strlen($form_state->getValue('message')) < 10) {
//      $form_state->setErrorByName('name', $this->t('Message should be at least 10 characters.'));
//    }
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState) {
    $controls = array();
    $session = $this->getRequest()->getSession();
    foreach ($formState->getValues() as $item => $value) {
      if (class_exists($item) && is_subclass_of($item, BaseControl::class)) {
        if ($value) {
          $controls[$item] = $value;
        }
        $session->set($item, $value);
      }
      elseif ($value === 1) {
        $args = ['%control' => $item];
        $this->messenger()
          ->addError($this->t('Requested invalid control %control', $args));
        $this->logger('qa')
          ->error('Requested invalid control %control', $args);
      }
    }

    $this->messenger()
      ->addStatus($this->t('Prepare to run these controls: @controls', [
        '@controls' => implode(', ', array_keys($controls)),
      ]));
    $batch = [
      'operations'       => [],
      'title'            => $this->t('QA Controls running'),
      'init_message'     => $this->t('QA Controls initializing'),
      'progress_message' => $this->t('current: @current, Remaining: @remaining, Total: @total'),
      'error_message'    => $this->t('Error in QA Control'),
      'finished'         => [ReportForm::class, 'runFinished'],
      // 'file'             => '', // only if outside module file
    ];

    foreach ($controls as $item => $value) {
      $batch['operations'][] = [[$this, 'runPass'], [$item]];
    }
    batch_set($batch);
  }

  /**
   * Batch conclusion callback.
   *
   * @param bool $success
   * @param array $results
   * @param array $operations
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public static function runFinished(bool $success, array $results, array $operations) {
    unset($results['#message']);
    if ($success) {
      $message = \Drupal::translation()->formatPlural(count($results),
        'One control pass ran.',
        '@count control passes ran.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
    $_SESSION['qa_results'] = $results;
    return new RedirectResponse(
      Url::fromRoute('qa.reports.results', [], ['absolute' => TRUE])->toString()
    );
  }

  /**
   * Batch progress step.
   *
   * @return void
   */
  public function runPass(string $className, array &$context) {
    $nameArg = array('@class' => $className);

    $control = new $className();
    if (!is_object($control)) {
      $this->messenger()
        ->addError($this->t('Cannot obtain an instance for @class', $nameArg));
      $context['results']['#message'] = $this->t('Control @class failed to run.', $nameArg);
      $context['message'] = $this->t('Control @class failed to run.', $nameArg);
      $context['results'][$className] = 'wow';
    }
    else {
      $this->messenger()
        ->addStatus($this->t('Running a control instance for @class', $nameArg));
      $pass = $control->run();
      if (!$pass->status) {
        $context['success'] = FALSE;
      }
      $context['results']['#message'][] = $this->t('Control @class ran', $nameArg);
      $context['message'] = [
        '#theme' => 'item_list',
        '#items' => $context['results']['#message'],
      ];
      $context['results'][$className] = $pass;
    }
  }

}
