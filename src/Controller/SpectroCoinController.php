<?php

namespace Drupal\commerce_spectrocoin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\commerce_order\Entity\OrderInterface;


class SpectroCoinController extends ControllerBase {
  public function callback() {
    $expected_keys = ['userId', 'merchantApiId', 'merchantId', 'apiId', 'orderId', 'payCurrency', 'payAmount', 'receiveCurrency', 'receiveAmount', 'receivedAmount', 'description', 'orderRequestId', 'status', 'sign'];

		$post_data = [];
		foreach ($expected_keys as $key) {
			if (isset($_POST[$key])) {
				$post_data[$key] = $_POST[$key];
			}
		}
		$callback = $this->scClient->spectrocoinProcessCallback($post_data);
  }

  public function success() {

  }

  public function failure() {

  }
}
