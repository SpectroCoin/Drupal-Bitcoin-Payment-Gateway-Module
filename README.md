Commerce SpectroCoin
---------------

This module integrates [SpectroCoin](https://spectrocoin.com/) Payments with [Drupal Commerce](https://drupal.org/project/commerce) to accept [Bitcoin](https://bitcoin.org) payments.

**INSTALLATION**

1. Download the module to sites/all/modules or sites/all/modules/contrib.
2. Install/Enable the module at admin/modules page.

**CONFIGURATION**

Configure SpectroCoin options in admin/commerce/config/payment-methods
by editing the SpectroCoin payment method. The configuration
options are part of the reaction rule settings.

**CURRENCY CONVERSION**

SpectroCoin only supports EUR, BTC payments.

If your shop uses additional currencies, you should take additional measures,
like through installing [Commerce Multicurrency](https://www.drupal.org/project/commerce_multicurrency), to be sure all orders are
converted to the currency, which is supported by SpectroCoin.
