<?php

namespace Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

interface SpectroCoinInterface extends OffsitePaymentGatewayInterface {
  public function createSpectroCoinInvoice(PaymentInterface $payment, array $extra);
}


