# SpectroCoin Drupal Commerce Crypto Payment Module

Integrate cryptocurrency payments seamlessly into your Drupal store with the [SpectroCoin Crypto Payment Module](https://spectrocoin.com/en/plugins/accept-bitcoin-Drupal.html). This module facilitates the acceptance of a variety of cryptocurrencies, enhancing payment options for your customers. Easily configure and implement secure transactions for a streamlined payment process on your Drupal website. Visit SpectroCoin Crypto Payment Module for Drupal to get started.

## Installation

0. [Drupal Commerce](https://www.drupal.org/project/commerce) module has to be installed and enabled.
1. Download latest release from github.
2. From Drupal admin dashboard navigate to <b>"Extend"</b>-><b>"Add new module"</b> -> upload module zip file.
   <br>OR<br>
   Access Drupal site root directory via ftp and navigate to <i>/modules/contrib/</i>. Extract module files, module folder name has to be <i>commerce_spectrocoin</i>.
3. From Drupal admin dashboard navigate to <b>"Commerce"</b> -> <b>"Configuration"</b> -> <b>"Payment gateways"</b> -> <b>"Add payment gateway"</b>.
4. In "Plugin" section select <b>"SpectroCoin(Redirect to SpectroCoin)"</b>.
5. Move to section [Setting up](#setting-up).

## Setting up

1. **[Sign up](https://auth.spectrocoin.com/signup)** for a SpectroCoin Account.
2. **[Log in](https://auth.spectrocoin.com/login)** to your SpectroCoin account.
3. On the dashboard, locate the **[Business](https://spectrocoin.com/en/merchants/projects)** tab and click on it.
4. Click on **[New project](https://spectrocoin.com/en/merchants/projects/new)**.
5. Fill in the project details and select desired settings (settings can be changed).
6. Click **"Submit"**.
7. Copy and paste the "Project id".
8. Click on the user icon in the top right and navigate to **[Settings](https://test.spectrocoin.com/en/settings/)**. Then click on **[API](https://test.spectrocoin.com/en/settings/api)** and choose **[Create New API](https://test.spectrocoin.com/en/settings/api/create)**.
9. Add "API name", in scope groups select **"View merchant preorders"**, **"Create merchant preorders"**, **"View merchant orders"**, **"Create merchant orders"**, **"Cancel merchant orders"** and click **"Create API"**.
10. Copy and store "Client id" and "Client secret". Save the settings.

## Test order creation on localhost

We gently suggest trying out the plugin in a server environment, as it will not be capable of receiving callbacks from SpectroCoin if it will be hosted on localhost. To successfully create an order on localhost for testing purposes, <b>change these 3 lines in <em>SCMechantClient.php spectrocoinCreateOrder() function</em></b>:

`'callbackUrl' => $request->getCallbackUrl()`, <br>
`'successUrl' => $request->getSuccessUrl()`, <br>
`'failureUrl' => $request->getFailureUrl()`

<b>To</b>

`'callbackUrl' => 'http://localhost.com'`, <br>
`'successUrl' => 'http://localhost.com'`, <br>
`'failureUrl' => 'http://localhost.com'`

Adjust it appropriately if your local environment URL differs.
Don't forget to change it back when migrating website to public.

## Changelog

### 1.0.0 MAJOR ():

_Updated_: Order creation API endpoint has been updated for enhanced performance and security.

_Removed_: Private key functionality and merchant ID requirement have been removed to streamline integration.

_Added_: OAuth functionality introduced for authentication, requiring Client ID and Client Secret for secure API access.

_Added_: API error logging and message displaying in order creation process.

_Migrated_: Since HTTPful is no longer maintained, we migrated to GuzzleHttp. In this case /vendor directory was added which contains GuzzleHttp dependencies.

_Reworked_: SpectroCoin callback handling was reworked. Added appropriate callback routing for success, fail and callback.

## Information

This client has been developed by SpectroCoin.com If you need any further support regarding our services you can contact us via:

E-mail: merchant@spectrocoin.com </br>
Skype: spectrocoin_merchant </br>
[Web](https://spectrocoin.com) </br>
[X (formerly Twitter)](https://twitter.com/spectrocoin) </br>
[Facebook](https://www.facebook.com/spectrocoin/)
