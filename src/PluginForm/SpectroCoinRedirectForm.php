<?php

namespace Drupal\commerce_spectrocoin\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class SpectroCoinRedirectForm extends BasePaymentOffsiteForm {
  /**
   * Builds the configuration form for the SpectroCoin redirect.
   * 
   * This form will be submitted to SpectroCoin for processing the payment. The function first retrieves the necessary payment and gateway plugin information. Then, it attempts to create a SpectroCoin invoice. If successful, it redirects the user to the SpectroCoin payment page. Otherwise, it displays an error message.
   *
   * @param array $form The form array.
   * @param FormStateInterface $form_state The form state object.
   * @return array The form structure or the redirect response.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway\SpectroCoinInterface $paymentGatewayPlugin */
    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();

    // Additional data for invoice creation can be passed in $extra.
    $extra = [];

    // Attempt to create a SpectroCoin invoice and obtain the redirect URL.
    $spectrocoinResponse = $paymentGatewayPlugin->createSpectroCoinInvoice($payment, $extra);
    $redirectUrl = $spectrocoinResponse->getRedirectUrl();

    // Check if a valid redirect URL was obtained.
    if (!isset($redirectUrl)) {
      // Display an error message if the redirect URL is missing.
      return [
        '#type' => 'inline_template',
        '#template' => '<span>{{ error_message | t }}</span>',
        '#context' => ['error_message' => 'Error: Unable to process the payment at this time.'],
      ];
    }

    // Prepare data for the redirect form.
    $data = [
      'version' => 'v1',
      'total' => $payment->getAmount()->getNumber(),
      // Include additional data required by SpectroCoin here.
    ];

    // Redirect the user to the SpectroCoin payment page with the necessary data.
    return $this->buildRedirectForm($form, $form_state, $redirectUrl, $data, self::REDIRECT_GET);
  }
}