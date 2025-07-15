<?php

namespace Drupal\commerce_spectrocoin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OldOrderCallback;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderCallback;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderStatusEnum;
use Drupal\commerce_spectrocoin\SCMerchantClient\SCMerchantClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Url;
use InvalidArgumentException;
use Exception;

/**
 * Controller for handling SpectroCoin callbacks and redirects.
 */
class SpectroCoinController extends ControllerBase
{

  /**
   * Processes the payment callback.
   *
   * Expects a POST request with the required callback data.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response with status 200 and "*ok*" if successful.
   */
  public function callback()
  {
    $request = \Drupal::request();
    if ($request->getMethod() !== 'POST') {
      \Drupal::logger('commerce_spectrocoin')
        ->error('SpectroCoin Error: Invalid request method, POST is required');
      return new Response('Invalid request method', 405);
    }
    try {
      $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
      if (stripos($contentType, 'application/json') !== false) {
        // if new callback
        $order_callback = $this->initCallbackFromJson();
        if (! $order_callback) {
          throw new InvalidArgumentException('Invalid JSON callback payload');
        }
        $sc_merchant_client = new SCMerchantClient(
          $this->configuration['project_id'],
          $this->configuration['client_id'],
          $this->configuration['client_secret']
        );

        $order_data = $sc_merchant_client->getOrderById($order_callback->getUuid());

        if (! is_array($order_data) || empty($order_data['orderId']) || empty($order_data['status'])) {
          throw new InvalidArgumentException('Malformed order data from API');
        }

        $raw_status = $order_data['status'];
        $raw_order_id = $order_data['orderId'];
      } else {
        // if legacy callback
        $order_callback = $this->initCallbackFromPost();
        $raw_status = $order_callback->getStatus();
        $raw_order_id = $order_callback->getOrderId();
      }

      list($order_id, $payment_id) = explode('-', $raw_order_id);

      if (!$order_id || !$payment_id) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Invalid combined order/payment ID.');
        return new Response('Invalid order/payment id', 400);
      }
      if (!$order_callback) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: No data received in callback');
        return new Response('Invalid callback data', 400);
      }
      $order = Order::load($order_id);
      if (!$order) {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Order not found - Order ID: ' . $order_id);
        return new Response('Order not found', 404);
      }

      $statusEnum = SpectroCoin_OrderStatusEnum::normalize($raw_status);
      switch ($statusEnum) {
        case SpectroCoin_OrderStatusEnum::NEW:
          break;
        case SpectroCoin_OrderStatusEnum::PENDING:
          break;
        case SpectroCoin_OrderStatusEnum::PAID:
          $order->set('state', 'completed');
          $order->set('cart', 0);
          break;
        case SpectroCoin_OrderStatusEnum::FAILED:
          $order->set('state', 'canceled');
          $order->set('cart', 0);
          break;
        case SpectroCoin_OrderStatusEnum::EXPIRED:
          $order->set('state', 'expired');
          $order->set('cart', 0);
          break;
        default:
          \Drupal::logger('commerce_spectrocoin')
            ->error('SpectroCoin Callback: Unknown order status - ' . $order_callback->getStatus());
          return new Response('Unknown order status: ' . $order_callback->getStatus(), 400);
      }
      $order->save();

      if ($statusEnum === SpectroCoin_OrderStatusEnum::PAID) { 
        $payment_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
        $payment = $payment_storage->load($payment_id);
        if ($payment) {
          $payment->setState('completed');
          $payment->save();
        } else {
          \Drupal::logger('commerce_spectrocoin')
            ->error('Payment not found for payment ID: ' . $payment_id);
        }
      }

      $response = new Response('*ok*', 200);
      $response->headers->set('Content-Type', 'text/plain');
      return $response;
    } catch (InvalidArgumentException $e) {
      \Drupal::logger('commerce_spectrocoin')
        ->error("Error processing callback: " . $e->getMessage());
      return new Response('Error processing callback', 400);
    } catch (Exception $e) {
      \Drupal::logger('commerce_spectrocoin')
        ->error("Error processing callback: " . $e->getMessage());
      return new Response('Error processing callback', 500);
    }
  }


  public function success()
  {
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

  public function failure()
  {
    $order_id = \Drupal::request()->query->get('order_id');
    if ($order_id) {
      $order = Order::load((int) $order_id);
      if ($order) {
        $order->set('state', 'canceled');
        $order->save();
      } else {
        \Drupal::logger('commerce_spectrocoin')
          ->error('SpectroCoin Error: Invalid Order ID in failure callback.');
      }
    } else {
      \Drupal::logger('commerce_spectrocoin')
        ->error('SpectroCoin Error: Order ID is not available in failure callback.');
    }
    $this->messenger()->addError($this->t('Your order was canceled. Please try again.'));
    return new RedirectResponse('/cart');
  }

  /**
   * Initializes the callback data from POST (form-encoded) request.
   * 
   * Callback format processed by this method is URL-encoded form data.
   * Example: merchantId=1387551&apiId=105548&userId=…&sign=…
   * Content-Type: application/x-www-form-urlencoded
   * These callbacks are being sent by old merchant projects.
   *
   * Extracts the expected fields from `$_POST`, validates the signature,
   * and returns an `OldOrderCallback` instance wrapping that data.
   *
   * @deprecated since v2.1.0
   *
   * @return SpectroCoin_OldOrderCallback|null  An `OldOrderCallback` if the POST body
   *                                contained valid data; `null` otherwise.
   */
  private function initCallbackFromPost()
  {
    $expected_keys = [
      'userId',
      'merchantApiId',
      'merchantId',
      'apiId',
      'orderId',
      'payCurrency',
      'payAmount',
      'receiveCurrency',
      'receiveAmount',
      'receivedAmount',
      'description',
      'orderRequestId',
      'status',
      'sign'
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
    return new SpectroCoin_OldOrderCallback($callback_data);
  }


  /**
   * Initializes the callback data from JSON request body.
   *
   * Reads the raw HTTP request body, decodes it as JSON, and returns
   * an OrderCallback instance if the payload is valid.
   *
   * @return SpectroCoin_OrderCallback|null  An OrderCallback if the JSON payload
   *                             contained valid data; null if the body
   *                             was empty.
   *
   * @throws \JsonException           If the request body is not valid JSON.
   * @throws \InvalidArgumentException If required fields are missing
   *                                   or validation fails in OrderCallback.
   *
   */
  private function initCallbackFromJson(): ?SpectroCoin_OrderCallback
  {
    $body = (string) \file_get_contents('php://input');
    if ($body === '') {
      \Drupal::logger('commerce_spectrocoin')->error('Empty JSON callback payload');
      return null;
    }

    $data = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    if (!\is_array($data)) {
      \Drupal::logger('commerce_spectrocoin')->error('JSON callback payload is not an object');
      return null;
    }

    return new SpectroCoin_OrderCallback(
      $data['id'] ?? null,
      $data['merchantApiId'] ?? null
    );
  }
}
