<?php

/**
 * Invariant tests for the payer-facing redirect endpoints.
 *
 * The success and failure routes are presentation only: they render or redirect
 * and never modify an order. Order state has a single owner, ::callback(), which
 * establishes that the request came from SpectroCoin before writing.
 *
 * These tests pin that separation so it is not eroded by a later change.
 *
 * Standalone by design: the module ships no PHPUnit setup, and a Drupal
 * bootstrap is not needed to observe which methods write.
 *
 * Run:  php tests/check-order-state-change.php
 */

// ---------------------------------------------------------------------------
// Stubs. Defined before the controller is loaded so its `use` targets resolve.
// ---------------------------------------------------------------------------

namespace Drupal\Core\Controller {
    class ControllerBase
    {
        public $messengerSpy;

        public function messenger()
        {
            return $this->messengerSpy;
        }

        public function t($string, array $args = [])
        {
            return $string;
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    class Response
    {
        public $content;
        public $status;
        public $headers;

        public function __construct($content = '', $status = 200)
        {
            $this->content = $content;
            $this->status = $status;
            $this->headers = new class {
                public function set($k, $v) {}
            };
        }
    }

    class RedirectResponse
    {
        private $target;

        public function __construct($target)
        {
            $this->target = $target;
        }

        public function getTargetUrl()
        {
            return $this->target;
        }
    }
}

namespace Drupal\commerce_order\Entity {

    /**
     * Spy order. Any call to set()/save() is a test failure for these routes.
     */
    class Order
    {
        public static $loadCalls = [];
        public static $instances = [];

        public $id;
        public $sets = [];
        public $saveCount = 0;

        public static function reset()
        {
            self::$loadCalls = [];
            self::$instances = [];
        }

        /**
         * Mirrors entity storage: NULL when no such order exists. Any positive
         * integer id is treated as an existing order, which is the realistic
         * case for a live shop with sequential order ids.
         */
        public static function load($id)
        {
            self::$loadCalls[] = $id;
            if (!is_numeric($id) || (int) $id <= 0) {
                return null;
            }
            $o = new self();
            $o->id = (int) $id;
            self::$instances[] = $o;
            return $o;
        }

        public static function totalSaves()
        {
            $n = 0;
            foreach (self::$instances as $o) {
                $n += $o->saveCount;
            }
            return $n;
        }

        public function id()
        {
            return $this->id;
        }

        public function set($field, $value)
        {
            $this->sets[] = [$field, $value];
        }

        public function save()
        {
            $this->saveCount++;
        }
    }
}

namespace {

    class SpyMessenger
    {
        public $errors = [];
        public function addError($m) { $this->errors[] = $m; }
    }

    class SpyLogger
    {
        public $lines = [];
        public function error($m, $c = []) { $this->lines[] = $m; }
        public function warning($m, $c = []) { $this->lines[] = $m; }
    }

    class SpyQuery
    {
        private $params;
        public function __construct(array $params) { $this->params = $params; }
        public function get($k) { return $this->params[$k] ?? null; }
    }

    class SpyRequest
    {
        public $query;
        public function __construct(array $params) { $this->query = new SpyQuery($params); }
        public function getSchemeAndHttpHost() { return 'https://shop.example'; }
        public function getMethod() { return 'GET'; }
    }

    /**
     * Minimal \Drupal static service locator.
     */
    class Drupal
    {
        public static $request;
        public static $logger;

        public static function request() { return self::$request; }
        public static function logger($channel) { return self::$logger; }
    }

    // -----------------------------------------------------------------------
    // Tiny test runner (same shape as the Zen Cart callback harness).
    // -----------------------------------------------------------------------

    class TestRunner
    {
        private $failures = [];
        private $passed = 0;
        private $failed = 0;

        public function assertTrue($cond, $message)
        {
            if (!$cond) {
                $this->failures[] = $message;
            }
        }

        public function assertSame($expected, $actual, $message)
        {
            if ($expected !== $actual) {
                $this->failures[] = $message
                    . ' (expected ' . var_export($expected, true)
                    . ', got ' . var_export($actual, true) . ')';
            }
        }

        public function run($name, callable $test)
        {
            $this->failures = [];
            try {
                $test($this);
            } catch (\Throwable $e) {
                $this->failures[] = 'threw ' . get_class($e) . ': ' . $e->getMessage();
            }
            if (empty($this->failures)) {
                $this->passed++;
                echo "  PASS  {$name}\n";
            } else {
                $this->failed++;
                echo "  FAIL  {$name}\n";
                foreach ($this->failures as $f) {
                    echo "          {$f}\n";
                }
            }
        }

        public function summary()
        {
            echo "\n{$this->passed} passed, {$this->failed} failed\n";
            return $this->failed === 0 ? 0 : 1;
        }
    }

    /**
     * Extracts one method body by brace matching, for the static guards.
     */
    function method_body($source, $signature)
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            throw new \RuntimeException("method not found: {$signature}");
        }
        $open = strpos($source, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') { $depth++; }
            if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        throw new \RuntimeException("unbalanced braces in {$signature}");
    }

    // -----------------------------------------------------------------------

    $controllerFile = __DIR__ . '/../src/Controller/SpectroCoinController.php';
    require_once $controllerFile;
    $source = file_get_contents($controllerFile);

    $routingFile = __DIR__ . '/../commerce_spectrocoin.routing.yml';
    $routing = file_get_contents($routingFile);

    $t = new TestRunner();

    echo "SpectroCoin Drupal Commerce — payer-redirect state-change regression\n\n";

    $makeController = function (array $query) {
        \Drupal::$request = new SpyRequest($query);
        \Drupal::$logger = new SpyLogger();
        \Drupal\commerce_order\Entity\Order::reset();

        $c = new \Drupal\commerce_spectrocoin\Controller\SpectroCoinController();
        $c->messengerSpy = new SpyMessenger();
        return $c;
    };

    // --- Redirect endpoints must not write ---------------------------------

    $t->run('failure() does not act on an order id from the query string', function ($t) use ($makeController) {
        $c = $makeController(['order_id' => '1337']);
        $c->failure();

        $t->assertSame(0, count(\Drupal\commerce_order\Entity\Order::$loadCalls),
            'Order::load() must not be called from failure()');
        $t->assertSame(0, \Drupal\commerce_order\Entity\Order::totalSaves(),
            'no order may be saved from failure()');
    });

    $t->run('failure() writes nothing for any of a range of order ids', function ($t) use ($makeController) {
        foreach (['1', '2', '999999', '0', '-1', 'abc', ''] as $id) {
            $c = $makeController(['order_id' => $id]);
            $c->failure();
            $t->assertSame(0, \Drupal\commerce_order\Entity\Order::totalSaves(),
                "no order may be saved from failure() for order_id={$id}");
        }
    });

    $t->run('failure() still shows the message and redirects to the cart', function ($t) use ($makeController) {
        $c = $makeController(['order_id' => '1337']);
        $r = $c->failure();

        $t->assertSame('/cart', $r->getTargetUrl(), 'failure() must redirect to the cart');
        $t->assertSame(1, count($c->messengerSpy->errors), 'failure() must show one error message');
    });

    // --- success() resolves without touching the order --------------------

    $t->run('success() does not load an order', function ($t) use ($makeController) {
        $c = $makeController(['order_id' => '1337']);
        $r = $c->success();

        $t->assertSame(0, count(\Drupal\commerce_order\Entity\Order::$loadCalls),
            'Order::load() must not be called from success()');
        $t->assertSame(0, \Drupal\commerce_order\Entity\Order::totalSaves(),
            'no order may be saved from success()');
        $t->assertSame('https://shop.example/checkout/1337/complete', $r->getTargetUrl(),
            'success() must redirect to the Commerce checkout completion route');
    });

    $t->run('success() rejects a non-numeric order id', function ($t) use ($makeController) {
        foreach (['abc', '', '0', '-5'] as $id) {
            $c = $makeController(['order_id' => $id]);
            $t->assertSame('/', $c->success()->getTargetUrl(),
                "success() must fall back to '/' for order_id={$id}");
        }
    });

    // --- Static guards against reintroduction --------------------------------

    $t->run('failure() body contains no entity write', function ($t) use ($source) {
        $body = method_body($source, 'public function failure()');
        $t->assertTrue(strpos($body, '->save(') === false, 'failure() must not call save()');
        $t->assertTrue(strpos($body, "->set(") === false, 'failure() must not call set()');
        $t->assertTrue(strpos($body, 'Order::load') === false, 'failure() must not load an order');
    });

    $t->run('success() body contains no entity write', function ($t) use ($source) {
        $body = method_body($source, 'public function success()');
        $t->assertTrue(strpos($body, '->save(') === false, 'success() must not call save()');
        $t->assertTrue(strpos($body, 'Order::load') === false, 'success() must not load an order');
    });

    // --- The authenticated path must remain intact ---------------------------

    $t->run('callback() still owns order state', function ($t) use ($source) {
        $body = method_body($source, 'public function callback()');
        $t->assertTrue(strpos($body, "\$order->set('state', 'canceled')") !== false,
            'callback() must still cancel on FAILED');
        $t->assertTrue(strpos($body, "\$order->set('state', 'expired')") !== false,
            'callback() must still expire on EXPIRED');
        $t->assertTrue(strpos($body, "\$order->set('state', 'completed')") !== false,
            'callback() must still complete on PAID');
        $t->assertTrue(strpos($body, '$order->save()') !== false,
            'callback() must still persist the order');
        $t->assertTrue(strpos($body, 'getOrderById') !== false,
            'callback() must still re-fetch authoritative status from the API');
        $t->assertTrue(strpos($body, 'isInformational()') !== false,
            'callback() must skip state changes for informational statuses');
    });

    $t->run('callback() cancels on CANCELLED as well as FAILED', function ($t) use ($source) {
        $body = method_body($source, 'public function callback()');
        $t->assertTrue(strpos($body, 'SpectroCoin_OrderStatusEnum::CANCELLED') !== false,
            'callback() must handle the CANCELLED status the API sends when an order is cancelled');
    });

    $t->run('the status enum accepts every status the API can send', function ($t) {
        require_once __DIR__ . '/../src/SCMerchantClient/data/SpectroCoin_OrderStatusEnum.php';
        $enum = \Drupal\commerce_spectrocoin\SCMerchantClient\data\SpectroCoin_OrderStatusEnum::class;
        $wire = [
            'NEW' => 1, 'PENDING' => 2, 'PAID' => 3, 'FAILED' => 4, 'EXPIRED' => 5,
            'LATE_CRYPTO_PAYMENT' => 10, 'PARTIAL_PAYMENT' => 11, 'UNDERPAID' => 12,
            'CANCELLED' => 13, 'INVALID_PAYMENT' => 14, 'PROCESSING_REFUND' => 17,
            'REFUNDED' => 18, 'REJECTED_REFUND' => 19,
            'PENDING_LATE_CRYPTO_PAYMENT' => 20, 'REJECTED' => 21,
            'TEST' => 6, 'TEST_PAID' => 15, 'TEST_EXPIRED' => 16,
        ];
        $cancellations = ['FAILED', 'CANCELLED', 'REJECTED', 'INVALID_PAYMENT'];
        $informational = ['PARTIAL_PAYMENT', 'UNDERPAID', 'LATE_CRYPTO_PAYMENT',
                          'PENDING_LATE_CRYPTO_PAYMENT', 'PROCESSING_REFUND',
                          'REFUNDED', 'REJECTED_REFUND',
                          'TEST', 'TEST_PAID', 'TEST_EXPIRED'];

        foreach ($wire as $name => $code) {
            $t->assertSame($name, $enum::normalize($name)->value,
                "normalize() must accept the {$name} status");
            $t->assertSame($name, $enum::normalize($code)->value,
                "normalize() must map legacy code {$code} to {$name}");
            $t->assertSame(in_array($name, $cancellations, true),
                $enum::normalize($name)->isCancellation(),
                "{$name}: isCancellation() classification");
            $t->assertSame(in_array($name, $informational, true),
                $enum::normalize($name)->isInformational(),
                "{$name}: isInformational() classification");
        }

        $threw = false;
        try { $enum::normalize('SOMETHING_NEW'); }
        catch (\InvalidArgumentException $e) { $threw = true; }
        $t->assertTrue($threw, 'an out-of-contract status must still be rejected');
    });

    $t->run('callback route is still POST-only', function ($t) use ($routing) {
        $t->assertTrue(strpos($routing, 'methods: [POST]') !== false,
            'the callback route must stay POST-only');
    });

    exit($t->summary());
}
