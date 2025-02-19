<?php

namespace Drupal\commerce_spectrocoin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderCallback;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_order\Entity\Order;
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
      
      // Extract the order ID. (Assuming a format like "123-abc")
      $order_id_parts = explode('-', $order_callback->getOrderId());
      $order_id = (int) $order_id_parts[0];
      if (!$order_id) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Order ID is invalid.');
        return new Response('Invalid order id', 400);
      }
      
      // Load the Drupal Commerce order.
      $order = Order::load($order_id);
      if (!$order) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Order not found - Order ID: ' . $order_id);
        return new Response('Order not found', 404);
      }

      // Map the callback status to a Drupal order state.
      $status = strtolower($order_callback->getStatus());
      switch ($status) {
        case 'new':
          break;

        case 'pending':
          break;

        case 'expired':
          $order->set('state', 'expired');
          break;

        case 'failed':
          $order->set('state', 'canceled');
          break;

        case 'paid':
          $order->set('state', 'completed');
          break;

        default:
          \Drupal::logger('commerce_spectrocoin')
            ->error('SpectroCoin Callback: Unknown order status - ' . $order_callback->getStatus());
          return new Response('Unknown order status: ' . $order_callback->getStatus(), 400);
      }
      $order->save();

      // Respond as expected by SpectroCoin.
      return new Response('*ok*', 200);
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

  /**
   * Redirects the customer after a successful payment.
   *
   * Typically, Drupal Commerce’s checkout completion page is at
   * "/checkout/complete". Adjust the URL below if needed.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function success() {
    return new RedirectResponse('/checkout/complete');
  }

  /**
   * Handles a canceled or failed payment.
   *
   * If an order ID is passed as a query parameter, the order is updated to
   * a canceled state. The customer is then redirected to the cart page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function failure() {
    // If an order_id is provided in the URL, attempt to update the order.
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
    // Add an error message for the customer.
    $this->messenger()->addError($this->t('Your order was canceled. Please try again.'));
    // Redirect to the cart page (default Drupal Commerce cart URL).
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
