<?php

namespace Drupal\commerce_spectrocoin\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

class SpectroCoinRedirectForm extends BasePaymentOffsiteForm
{
  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway\SpectroCoinInterface $paymentGatewayPlugin*/
    $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();

    $extra = [

    ];

    $spectrocoinResponse = $paymentGatewayPlugin->createSpectroCoinInvoice($payment, $extra);
    $redirectUrl = $spectrocoinResponse->getRedirectUrl();
    if (!isset($redirectUrl)) {
      return [
        '#type' => 'inline_template',
        '#template' => "<span>{{ '" . $spectrocoinResponse . "' | t }}</span>",
      ];
    }

    $data = [
      'version' => 'v1',
      'total' => $payment->getAmount()->getNumber(),
    ];


    $response = $this->buildRedirectForm(
      $form,
      $form_state,
      $spectrocoinResponse->getRedirectUrl(),
      $data
    );

    return $response;
  }

  /**
   * Redirects to a previous checkout step on error.
   *
   * @throws \Drupal\commerce\Response\NeedsRedirectException
   */
  protected function redirectToPreviousStep()
  {
    //    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
//    $payment = $this->entity;
//
//    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
//    $order = $payment->getOrder();
//
//    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
//    $checkout_flow = $order->get('checkout_flow')->entity;
//    /** @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesInterface $checkout_flow_plugin */
//    $checkout_flow_plugin = $checkout_flow->getPlugin();
//    $step_id = $checkout_flow_plugin->getPane('payment_information')->getStepId();
//    return $checkout_flow_plugin->redirectToStep($step_id);
  }

}