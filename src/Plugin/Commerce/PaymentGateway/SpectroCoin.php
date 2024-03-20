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

define('API_URL', 'https://test.spectrocoin.com/api/public');
define('AUTH_URL', 'https://test.spectrocoin.com/api/public/oauth/token');


class SpectroCoin extends OffsitePaymentGatewayBase
{
  /**
   * Summary of __construct
   * @param array $configuration
   * @param mixed $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   * @param \Drupal\Component\Datetime\TimeInterface $time
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $payment_type_manager,
      $payment_method_type_manager,
      $time
    );
  }


  /**
   * Summary of defaultConfiguration
   * @return array
   */
  public function defaultConfiguration()
  {
    return [
      'checkout_display' => 'both',
      'project_id' => '',
      'client_id' => '',
      'client_secret' => '',
    ] + parent::defaultConfiguration();
  }


  /**
   * Summary of buildConfigurationForm
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return array
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['project_id'] = array(
      '#type' => 'textfield',
      '#default_value' => $this->configuration['project_id'],
      '#title' => t('Project Id'),
      '#size' => 45,
      '#maxlength' => 130,
      '#required' => TRUE,
    );

    $form['client_id'] = array(
      '#type' => 'textfield',
      '#default_value' => $this->configuration['client_id'],
      '#title' => t('Client Id'),
      '#size' => 45,
      '#maxlength' => 200,
      '#required' => TRUE,
    );

    $form['client_secret'] = array(
      '#type' => 'textfield',
      '#default_value' => $this->configuration['client_secret'],
      '#title' => t('Client Secret'),
      '#size' => 45,
      '#maxlength' => 200,
      '#required' => TRUE,
    );

    return $form;
  }

  /**
   * Summary of validateConfigurationForm
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return void
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateConfigurationForm($form, $form_state);

  }


  /**
   * Summary of submitConfigurationForm
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return void
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    if (!$form_state->getErrors()) {

      parent::submitConfigurationForm($form, $form_state);
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['project_id'] = $values['project_id'];
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['client_secret'] = $values['client_secret'];
    }
  }

  /**
   * Summary of createSpectroCoinInvoice
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @param array $extra
   * @return mixed
   */
  public function createSpectroCoinInvoice(PaymentInterface $payment, array $extra)
  {
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

    $paymentAmount = $payment->getAmount();

    $payment = $paymentStorage->create([
      'state' => 'Open',
      'amount' => $payment->getAmount(),
      'payment_gateway' => $this->entityId,
      'payment_method' => 'spectrocoin',
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'authorized' => $this->time->getRequestTime(),
    ]);

    $payment->save();

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
    $callback_url = Url::fromRoute('commerce_spectrocoin.callback', ['commerce_order' => $order_id, 'commerce_payment' => $payment_id], ['absolute' => TRUE])->toString();
    $success_url = Url::fromRoute('commerce_spectrocoin.success', ['commerce_order' => $order_id], ['absolute' => TRUE])->toString();
    $failure_url = Url::fromRoute('commerce_spectrocoin.failure', ['commerce_order' => $order_id], ['absolute' => TRUE])->toString();
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

    if($createOrderResponse instanceof SpectroCoin_ApiError)
      $payment->setState('failed');
      $payment->save();
    return $createOrderResponse;
  }
}