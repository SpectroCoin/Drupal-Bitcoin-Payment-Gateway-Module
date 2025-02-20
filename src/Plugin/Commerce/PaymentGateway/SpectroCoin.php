<?php
namespace Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

use Drupal\commerce_spectrocoin\SCMerchantClient\messages\SpectroCoin_CreateOrderRequest;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_ApiError;
use Drupal\commerce_spectrocoin\SCMerchantClient\SCMerchantClient;

define('API_URL', 'https://spectrocoin.com/api/public');
define('AUTH_URL', 'https://spectrocoin.com/api/public/oauth/token');

/**
 * Provides the SpectroCoin payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "spectrocoin",
 *   label = @Translation("SpectroCoin"),
 *   display_label = @Translation("Redirect to SpectroCoin"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_spectrocoin\PluginForm\SpectroCoinRedirectForm"
 *   },
 * )
 */
class SpectroCoin extends OffsitePaymentGatewayBase {

  public function defaultConfiguration() {
    return [
      'checkout_display' => 'both',
      'project_id' => '',
      'client_id' => '',
      'client_secret' => '',
    ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['project_id'] = [
      '#type' => 'textfield',
      '#default_value' => $this->configuration['project_id'],
      '#title' => t('Project Id'),
      '#size' => 45,
      '#maxlength' => 130,
      '#required' => TRUE,
    ];

    $form['client_id'] = [
      '#type' => 'textfield',
      '#default_value' => $this->configuration['client_id'],
      '#title' => t('Client Id'),
      '#size' => 45,
      '#maxlength' => 200,
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#default_value' => $this->configuration['client_secret'],
      '#title' => t('Client Secret'),
      '#size' => 45,
      '#maxlength' => 200,
      '#required' => TRUE,
    ];

    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      parent::submitConfigurationForm($form, $form_state);
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['project_id'] = $values['project_id'];
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['client_secret'] = $values['client_secret'];
    }
  }

  /**
   * Creates a SpectroCoin invoice.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @param array $extra
   *
   * @return mixed
   */
  public function createSpectroCoinInvoice(PaymentInterface $payment, array $extra) {
    $order = $payment->getOrder();
    
    \Drupal::logger('commerce_spectrocoin')->debug('Before updating, order ID ' . $order->id() . ' is in state: ' . $order->getState()->value);
    
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    
    $paymentAmount = $payment->getAmount();
    
    // Create a new Payment entity.
    $payment = $paymentStorage->create([
      'state' => 'Open',
      'amount' => $payment->getAmount(),
      'payment_gateway' => $payment->getPaymentGateway()->id(),
      'payment_method' => 'spectrocoin',
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'authorized' => $this->time->getRequestTime(),
    ]);
    
    $payment->save();
    
    // If the order is still in 'draft', update it to 'pending'
    if ($order->getState()->value === 'draft') {
      $order->set('state', 'pending');
      $order->save();
      \Drupal::logger('commerce_spectrocoin')->debug('Order ID ' . $order->id() . ' updated to state: ' . $order->getState()->value);
    }
    else {
      \Drupal::logger('commerce_spectrocoin')->debug('Order ID ' . $order->id() . ' was already in state: ' . $order->getState()->value);
    }
    
    $configuration = $this->getConfiguration();
    $client = new SCMerchantClient(
      AUTH_URL,
      API_URL,
      $configuration['project_id'],
      $configuration['client_id'],
      $configuration['client_secret']
    );
    $payment_id = $payment->id();
    $order_id = $order->id();
    $order_description = "Payment for order {$order_id}, payment {$payment_id}";
    $receive_amount = $paymentAmount->getNumber();
    $receive_currency_code = $paymentAmount->getCurrencyCode();
    $pay_currency_code = 'BTC';
    
    // Force HTTPS in generated URLs.
    $callback_url = Url::fromRoute('commerce_spectrocoin.callback', [
      'commerce_order' => $order_id,
      'commerce_payment' => $payment_id
    ], ['absolute' => TRUE, 'https' => TRUE])->toString();
    $success_url = Url::fromRoute('commerce_spectrocoin.success', [
      'commerce_order' => $order_id
    ], ['absolute' => TRUE, 'https' => TRUE])->toString();
    $failure_url = Url::fromRoute('commerce_spectrocoin.failure', [
      'commerce_order' => $order_id
    ], ['absolute' => TRUE, 'https' => TRUE])->toString();
    
    \Drupal::logger('commerce_spectrocoin')->debug("Generated callback_url: $callback_url");
    \Drupal::logger('commerce_spectrocoin')->debug("Generated success_url: $success_url");
    \Drupal::logger('commerce_spectrocoin')->debug("Generated failure_url: $failure_url");
    
    $locale = 'en';
    $createOrderRequest = new SpectroCoin_CreateOrderRequest(
      $order_id,
      $order_description,
      $receive_amount,
      $receive_currency_code,
      null,
      $pay_currency_code,
      $callback_url,
      $success_url,
      $failure_url,
      $locale
    );
    $createOrderResponse = $client->spectroCoinCreateOrder($createOrderRequest);
    
    if ($createOrderResponse instanceof SpectroCoin_ApiError) {
      $payment->setState('failed');
      $payment->save();
    }
    return $createOrderResponse;
  }
  
}
