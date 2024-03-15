<?php
namespace Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use SCMerchantClient\messages\CreateOrderRequest;
use SCMerchantClient\SCMerchantClient;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;

include_once(__DIR__ . '/../../../SCMerchantClient/SCMerchantClient.php');
include_once(__DIR__ . '/../../../SCMerchantClient/messages/CreateOrderRequest.php');
define('SC_API_URL', 'https://spectrocoin.com/api/merchant/1');

/**
 * Provides the QuickPay offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "spectrocoin",
 *   label = @Translation("SpectroCoin(Redirect to SpectroCoin)"),
 *   display_label = @Translation("SpectroCoin"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_spectrocoin\PluginForm\SpectroCoinRedirectForm",
 *   },
 * )
 */

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
      'userId' => '',
      'merchantApiId' => '',
      'culture' => 'en',
      'private_key' => '',
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

    $form['userId'] = array(
      '#type' => 'textfield',
      '#default_value' => $this->configuration['userId'],
      '#title' => t('Merchant Id'),
      '#size' => 45,
      '#maxlength' => 130,
      '#required' => TRUE,
    );

    $form['merchantApiId'] = array(
      '#type' => 'textfield',
      '#default_value' => $this->configuration['merchantApiId'],
      '#title' => t('Project Id'),
      '#size' => 45,
      '#maxlength' => 130,
      '#required' => TRUE,
    );

    $form['culture'] = array(
      '#type' => 'select',
      '#default_value' => $this->configuration['culture'],
      '#title' => t('Language for response'),
      '#options' => array('en', 'lt', 'ru', 'de'),
      '#required' => TRUE,
    );

    $is_private_key_set = !empty($this->configuration['private_key']);

    $form['private_key'] = array(
      '#type' => 'textarea',
      '#title' => t('Private key'),
      '#default_value' => '',
      '#required' => !$is_private_key_set,
      '#attributes' => array(
        'placeholder' => t('If you have already entered your private key before, you should leave this field blank, unless you want to change the stored private key.'),
      ),
    );

    $form['private_key_old'] = array(
      '#type' => 'value',
      '#value' => $this->configuration['private_key'],
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
      $this->configuration['userId'] = $values['userId'];
      $this->configuration['merchantApiId'] = $values['merchantApiId'];
      $this->configuration['culture'] = $values['culture'];
      $this->configuration['private_key'] = $values['private_key'];
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

    module_load_include('php', 'commerce_spectrocoin', 'SCMerchantClient/SCMerchantClient');

    $configuration = $this->getConfiguration();

    $client = new SCMerchantClient(
      SC_API_URL,
      $configuration['userId'],
      $configuration['merchantApiId']
    );

    $privateKey = $configuration['private_key'];

    $client->setPrivateMerchantKey($privateKey);

    $orderDescription = $payment->id();
    $currency = $paymentAmount->getCurrencyCode();
    $amount = $paymentAmount->getNumber();
    $createOrderRequest = new SpectroCoin_CreateOrderRequest(
      null,
      "BTC",
      null,
      $currency,
      $amount,
      $orderDescription,
      "en",
      'https://localhost.com/callback.php',
      'https://localhost.com/success.php',
      'https://localhost.com/failure.php'
    );
    $createOrderResponse = $client->spectroCoinCreateOrder($createOrderRequest);

    return $createOrderResponse;
  }
}