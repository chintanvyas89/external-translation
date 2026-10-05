<?php

namespace Drupal\tmgmt_external_translation_interface\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\tmgmt_external_translation_interface\InterfaceTranslation\InterfaceTranslationManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manual trigger (per the POC decision: retranslation is manual only) for
 * machine-translating untranslated interface strings.
 */
class RequestInterfaceTranslationForm extends FormBase {

  public function __construct(
    protected InterfaceTranslationManagerInterface $manager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('tmgmt_external_translation_interface.manager'), $container->get('language_manager'));
  }

  public function getFormId() {
    return 'tmgmt_external_translation_interface_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      if (!$language->isDefault()) {
        $options[$language->getId()] = $language->getName();
      }
    }
    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Target language'),
      '#options' => $options,
    ];
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum strings to translate'),
      '#default_value' => 50,
      '#min' => 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request machine translation'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $messages = $this->manager->translateUntranslatedStrings(
      $form_state->getValue('langcode'),
      (int) $form_state->getValue('limit'),
    );
    foreach ($messages as $message) {
      $this->messenger()->addStatus($message);
    }
  }

}
