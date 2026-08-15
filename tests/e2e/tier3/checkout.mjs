/**
 * Drives a real shopper through Drupal Commerce's checkout in a real browser.
 *
 * Tier 2 calls createSpectroCoinInvoice() directly, which proves the payload
 * and the callback contract but never that a shopper can reach the gateway.
 * SpectroCoin is an off-site Commerce payment gateway offered from a radio
 * button in the "order_information" checkout pane, so "is it offered, and does
 * selecting it actually redirect the shopper" is a question only a browser
 * walking the real flow can answer.
 *
 * tier3.sh seeds two stock "manual" gateways alongside SpectroCoin. That is
 * deliberate: Commerce hides the payment-method radios entirely when only one
 * gateway is configured, so a fixture with just ours would make "the gateway
 * is offered" untestable in general, and would make the negative run (ours
 * disabled) collapse to a single remaining gateway with nothing to control
 * against. Two stock gateways plus SpectroCoin means the radios stay visible,
 * with or without ours in the mix.
 *
 * Prints "PASS <name>" / "FAIL <name>" / "INFO <name>" lines for the shell
 * wrapper to count, and exits non-zero if anything failed.
 */

import { chromium } from 'playwright';

const SHOP = process.env.SHOP_URL || 'http://shop.test';
const PRODUCT_URL = process.env.PRODUCT_URL || `${SHOP}/product/1`;
const GATEWAY_LABEL = process.env.GATEWAY_LABEL || 'Redirect to SpectroCoin';
const STOCK_LABEL = process.env.STOCK_LABEL || 'Cash on delivery';

let failed = 0;
const pass = (m) => console.log(`PASS ${m}`);
const fail = (m) => { failed++; console.log(`FAIL ${m}`); };
const info = (m) => console.log(`INFO ${m}`);

const browser = await chromium.launch();
// The stub answers as spectrocoin.com with a certificate from a CA the harness
// minted; the browser has no reason to trust it.
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.setDefaultTimeout(30000);

const shot = async (name) => {
  try { await page.screenshot({ path: `/work/artifacts/${name}.png`, fullPage: true }); } catch {}
};

try {
  // ---- add to cart ------------------------------------------------------
  await page.goto(PRODUCT_URL, { waitUntil: 'domcontentloaded' });
  const addToCart = page.locator('input[value="Add to cart"]').first();
  if (await addToCart.count()) {
    await addToCart.click();
    await page.waitForTimeout(1500);
    pass('product can be added to the cart');
  } else {
    fail('no add-to-cart button on the product page');
    await shot('product');
  }

  // ---- to checkout --------------------------------------------------------
  await page.goto(`${SHOP}/cart`, { waitUntil: 'domcontentloaded' });
  const checkoutBtn = page.locator('input[value="Checkout"]').first();
  if (!(await checkoutBtn.count())) {
    fail('no checkout button on the cart page');
    await shot('cart');
    throw new Error('cannot reach checkout');
  }
  await checkoutBtn.click();
  await page.waitForTimeout(2000);

  // ---- guest login step ---------------------------------------------------
  const guestBtn = page.locator('input[value="Continue as Guest"]').first();
  if (await guestBtn.count()) {
    await guestBtn.click();
    await page.waitForTimeout(2000);
  }
  info(`checkout step: ${page.url()}`);

  // ---- order information: contact + billing address ----------------------
  const fill = async (sel, value) => {
    const el = page.locator(sel).first();
    if (await el.count()) { await el.fill(value); return true; }
    return false;
  };

  await fill('#edit-contact-information-email', 'tier3@example.com');
  await fill('#edit-contact-information-email-confirm', 'tier3@example.com');
  const ADDR = 'payment_information[billing_information][address][0][address]';
  await fill(`[name="${ADDR}[given_name]"]`, 'Tier');
  await fill(`[name="${ADDR}[family_name]"]`, 'Three');
  await fill(`[name="${ADDR}[address_line1]"]`, '1 Test Street');
  await fill(`[name="${ADDR}[postal_code]"]`, '01100');
  await fill(`[name="${ADDR}[locality]"]`, 'Vilnius');

  // ---- the stock-method control -------------------------------------------
  // If the payment step is empty for every method, that is a fixture problem,
  // not evidence against our gateway. A stock method must be offered too, or
  // "ours is missing" means nothing.
  const stockOffered = await page.getByText(STOCK_LABEL, { exact: false }).count();
  if (stockOffered > 0) {
    pass(`a stock payment method (${STOCK_LABEL}) is offered too (control for an empty payment step)`);
  } else {
    fail(`no stock payment method is offered, not even ${STOCK_LABEL} - this is a fixture problem, not our module`);
    await shot('order-information-no-control');
  }

  // ---- the assertion this tier exists for ---------------------------------
  const offered = await page.getByText(GATEWAY_LABEL, { exact: false }).count();
  if (offered > 0) {
    pass('the gateway is offered at checkout');
  } else {
    fail('the gateway is NOT offered at checkout');
    await shot('order-information-no-gateway');
  }

  const radio = page.locator('label', { hasText: GATEWAY_LABEL }).first();
  if (await radio.count()) {
    await radio.click();
    pass('the gateway can be selected');
  } else {
    fail('the gateway could not be selected');
    await shot('order-information-select');
  }
  await page.waitForTimeout(1500);

  const continueBtn = page.locator('input[value="Continue to review"]').first();
  if (!(await continueBtn.count())) {
    fail('no continue-to-review button');
    await shot('order-information-no-continue');
    throw new Error('cannot reach review');
  }
  await continueBtn.click();
  await page.waitForTimeout(2500);
  info(`review step: ${page.url()}`);

  // ---- place the order -----------------------------------------------------
  const placeOrder = page.locator('input[value="Pay and complete purchase"]').first();
  if (!(await placeOrder.count())) {
    fail('no place-order button on the review step');
    await shot('review-no-button');
  } else {
    await Promise.all([
      page.waitForURL(/spectrocoin\.com\/pay\//, { timeout: 45000 }).catch(() => {}),
      placeOrder.click(),
    ]);
    await page.waitForTimeout(3000);

    const url = page.url();
    info(`landed on: ${url}`);
    if (/spectrocoin\.com\/pay\//.test(url)) {
      pass('placing the order redirects the shopper to SpectroCoin');
    } else {
      fail(`placing the order did not redirect to SpectroCoin (landed on ${url})`);
      await shot('after-place-order');
    }
  }
} catch (err) {
  fail(`browser run threw: ${err.message.split('\n')[0]}`);
  await shot('threw');
} finally {
  await browser.close();
}

process.exit(failed === 0 ? 0 : 1);
