<?php

namespace Drupal\commerce_spectrocoin\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_ApiError;
use Symfony\Component\HttpFoundation\RedirectResponse; 
use Drupal\Core\Url;

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

        /** @var \Drupal\commerce_spectrocoin\Plugin\Commerce\PaymentGateway\SpectroCoinInterface $paymentGatewayPlugin */
        $paymentGatewayPlugin = $payment->getPaymentGateway()->getPlugin();
        $extra = [];

        try {
            $spectrocoinResponse = $paymentGatewayPlugin->createSpectroCoinInvoice($payment, $extra);

            if ($spectrocoinResponse instanceof SpectroCoin_ApiError) {
                \Drupal::logger('commerce_spectrocoin')->error('SpectroCoin Error: Code @code, Message: @message', [
                    '@code' => $spectrocoinResponse->getCode(),
                    '@message' => $spectrocoinResponse->getMessage(),
                ]);

                \Drupal::messenger()->addError(t('There was an issue processing your payment: @message', ['@message' => $spectrocoinResponse->getMessage()]));

                return $form;
            }

            $redirectUrl = $spectrocoinResponse->getRedirectUrl();
            if (!isset($redirectUrl)) {
                \Drupal::messenger()->addError(t('Error: SpectroCoin response did not contain a redirect URL.'));
                return $form;
            }

            $data = [
                'version' => 'v1',
                'total' => $payment->getAmount()->getNumber(),
            ];

            $redirectUrl = Url::fromUri($redirectUrl, ['query' => $data])->toString();
            $response = new RedirectResponse($redirectUrl);
            $response->send();

            return $form;
        } catch (\Exception $e) {
            \Drupal::logger('commerce_spectrocoin')->error('Unexpected error occurred: @message', ['@message' => $e->getMessage()]);
            \Drupal::messenger()->addError(t('An unexpected error occurred. Please try again.'));
            return $form;
        }
    }
}