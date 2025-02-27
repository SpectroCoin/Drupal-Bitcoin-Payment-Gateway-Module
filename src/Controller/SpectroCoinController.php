<?php
namespace Drupal\commerce_spectrocoin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderCallback;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Url;
use InvalidArgumentException;
use Exception;

/**
 * Controller for handling SpectroCoin callbacks and redirects.
 */
class SpectroCoinController extends ControllerBase {

  /**
   * Processes the payment callback.
   *
   * Expects a POST request with the required callback data.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response with status 200 and "*ok*" if successful.
   */
  public function callback() {
    $request = \Drupal::request();
    if ($request->getMethod() !== 'POST') {
      \Drupal::logger('commerce_spectrocoin')
        ->error('SpectroCoin Error: Invalid request method, POST is required');
      return new Response('Invalid request method', 405);
    }
    try {
      $order_callback = $this->initCallbackFromPost();
      if (!$order_callback) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: No data received in callback');
        return new Response('Invalid callback data', 400);
      }
      
      $combined = $order_callback->getOrderId();
      \Drupal::logger('commerce_spectrocoin')->debug('Combined order/payment ID: ' . $combined);
      
      list($order_id, $payment_id) = explode('-', $combined);
      
      if (!$order_id || !$payment_id) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Invalid combined order/payment ID.');
        return new Response('Invalid order/payment id', 400);
      }
      
      $order = Order::load($order_id);
      if (!$order) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Order not found - Order ID: ' . $order_id);
        return new Response('Order not found', 404);
      }

      $status = strtolower($order_callback->getStatus());
      
      switch ($status) {
        case 5:
          $order->set('state', 'expired');
          $order->set('cart', 0);
          break;
        case 4:
          $order->set('state', 'canceled');
          $order->set('cart', 0);
          break;
        case 3:
          $order->set('state', 'completed');
          $order->set('cart', 0);
          break;
        default:
          \Drupal::logger('commerce_spectrocoin')
            ->error('SpectroCoin Callback: Unknown order status - ' . $order_callback->getStatus());
          return new Response('Unknown order status: ' . $order_callback->getStatus(), 400);
      }
      $order->save();

      if ($status == 3) {  // completed
        $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
        $payment = $payment_storage->load($payment_id);
        if ($payment) {
          $payment->setState('completed');
          $payment->save();
        }
        else {
          \Drupal::logger('commerce_spectrocoin')
            ->error('Payment not found for payment ID: ' . $payment_id);
        }
      }

      $response = new Response('*ok*', 200);
      $response->headers->set('Content-Type', 'text/plain');
      return $response;
    }
    catch (InvalidArgumentException $e) {
      \Drupal::logger('commerce_spectrocoin')
        ->error("Error processing callback: " . $e->getMessage());
      return new Response('Error processing callback', 400);
    }
    catch (Exception $e) {
      \Drupal::logger('commerce_spectrocoin')
        ->error("Error processing callback: " . $e->getMessage());
      return new Response('Error processing callback', 500);
    }
  }


  public function success() {
    $order_id = \Drupal::request()->query->get('order_id');
    if ($order_id) {
      $order = Order::load((int) $order_id);
      if ($order) {
        $base_url = \Drupal::request()->getSchemeAndHttpHost();
        $success_url = $base_url . '/' . 'checkout/' . $order->id() . '/complete';
        return new RedirectResponse($success_url);
      }
    }
    return new RedirectResponse('/');
  }

  public function failure() {
    $order_id = \Drupal::request()->query->get('order_id');
    if ($order_id) {
      $order = Order::load((int) $order_id);
      if ($order) {
        $order->set('state', 'canceled');
        $order->save();
      }
      else {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Invalid Order ID in failure callback.');
      }
    }
    else {
      \Drupal::logger('commerce_spectrocoin')
        ->error('SpectroCoin Error: Order ID is not available in failure callback.');
    }
    $this->messenger()->addError($this->t('Your order was canceled. Please try again.'));
    return new RedirectResponse('/cart');
  }

  /**
   * Initializes the callback data from the POST request.
   *
   * @return \Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderCallback|null
   *   The OrderCallback object if valid data was found, or NULL otherwise.
   */
  private function initCallbackFromPost() {
    $expected_keys = [
      'userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId',
      'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount',
      'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'
    ];

    $callback_data = [];
    foreach ($expected_keys as $key) {
      if (isset($_POST[$key])) {
        $callback_data[$key] = $_POST[$key];
      }
    }
    if (empty($callback_data)) {
      \Drupal::logger('commerce_spectrocoin')->error("No data received in callback");
      return null;
    }
    return new SpectroCoin_OrderCallback($callback_data);
  }
}
