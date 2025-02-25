<?php

require_once dirname(__FILE__) . '/interfaces/msCartInterface.php';

class msCartHandler implements msCartInterface
{
    /** @var modX $modx */
    public $modx;
    /** @var miniShop2 $ms2 */
    public $ms2;
    /** @var array $config */
    public $config = array();
    /** @var array $cart */
    protected $cart;
    protected $ctx = 'web';
    protected $storage = 'session';
    protected $storageHandler;


    /**
     * msCartHandler constructor.
     *
     * @param miniShop2 $ms2
     * @param array $config
     */
    public function __construct(miniShop2 $ms2, array $config = array())
    {
        $this->ms2 = $ms2;
        $this->modx = $ms2->modx;

        $this->storage = $this->modx->getOption('ms2_tmp_storage', null, 'session');
        $this->storageInit();

        $this->config = array_merge(array(
            'cart' => $this->storageHandler->get(),
            'max_count' => $this->modx->getOption('ms2_cart_max_count', null, 1000, true),
            'allow_deleted' => false,
            'allow_unpublished' => false,
        ), $config);

        $this->cart = &$this->config['cart'];
        $this->modx->lexicon->load('minishop2:cart');

        if (empty($this->cart) || !is_array($this->cart)) {
            $this->cart = array();
        }
    }


    /**
     * @param string $ctx
     *
     * @return bool
     */
    public function initialize($ctx = 'web')
    {
        $ms2_cart_context = (bool)$this->modx->getOption('ms2_cart_context', null, '0', true);
        if ($ms2_cart_context) {
            $ctx = 'web';
        }
        $this->ctx = $ctx;
        $this->storageHandler->setContext($this->ctx);
        return true;
    }


    /**
     * @param int $id
     * @param int $count
     * @param array $options
     *
     * @return array|string
     */
    public function add($id, $count = 1, $options = array())
    {
        if (empty($id) || !is_numeric($id)) {
            return $this->error('ms2_cart_add_err_id');
        }
        $count = intval($count);
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            $options = array();
        }

        $filter = array('id' => $id, 'class_key' => 'msProduct');
        if (!$this->config['allow_deleted']) {
            $filter['deleted'] = 0;
        }
        if (!$this->config['allow_unpublished']) {
            $filter['published'] = 1;
        }
        /** @var msProduct $product */
        $product = $this->modx->getObject('msProduct', $filter);
        if ($product) {
            if ($count > $this->config['max_count'] || $count <= 0) {
                return $this->error('ms2_cart_add_err_count', $this->status(), array('count' => $count));
            }

            /* You can prevent add of product to cart by adding some text to $modx->event->_output
            <?php
                    if ($modx->event->name = 'msOnBeforeAddToCart') {
                        $modx->event->output('Error');
                    }

            // Also you can modify $count and $options variables by add values to $this->modx->event->returnedValues
                <?php
                    if ($modx->event->name = 'msOnBeforeAddToCart') {
                        $values = & $modx->event->returnedValues;
                        $values['count'] = $count + 10;
                        $values['options'] = array('size' => '99');
                    }
            */

            $response = $this->ms2->invokeEvent('msOnBeforeAddToCart', array(
                'product' => $product,
                'count' => $count,
                'options' => $options,
                'cart' => $this,
            ));
            if (!($response['success'])) {
                return $this->error($response['message']);
            }
            $price = $product->getPrice();
            $oldPrice = $product->get('old_price');
            $weight = $product->getWeight();
            $count = $response['data']['count'];
            $options = $response['data']['options'];
            $discount_price = $oldPrice > 0 ? $oldPrice - $price : 0;
            $discount_cost = $discount_price * $count;

            $key = md5($id . $price . $weight . (json_encode($options)));
            if (array_key_exists($key, $this->cart)) {
                return $this->change($key, $this->cart[$key]['count'] + $count);
            } else {
                $ctx_key = 'web';
                $ms2_cart_context = (bool)$this->modx->getOption('ms2_cart_context', null, '0', true);
                if (!$ms2_cart_context) {
                    $ctx_key = $this->ctx;
                }

                $cartItem = array(
                    'id' => $id,
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'discount_price' => $discount_price,
                    'discount_cost' => $discount_cost,
                    'weight' => $weight,
                    'count' => $count,
                    'options' => $options,
                    'ctx' => $ctx_key,
                    'key' => $key
                );
                $this->cart = $this->storageHandler->add($cartItem);
                $response = $this->ms2->invokeEvent('msOnAddToCart', array('key' => $key, 'cart' => $this));
                if (!$response['success']) {
                    return $this->error($response['message']);
                }

                return $this->success(
                    'ms2_cart_add_success',
                    $this->status(array('key' => $key)),
                    array('count' => $count)
                );
            }
        }

        return $this->error('ms2_cart_add_err_nf', $this->status());
    }


    /**
     * @param string $key
     *
     * @return array|string
     */
    public function remove($key)
    {
        if (array_key_exists($key, $this->cart)) {
            $response = $this->ms2->invokeEvent('msOnBeforeRemoveFromCart', array('key' => $key, 'cart' => $this));
            if (!$response['success']) {
                return $this->error($response['message']);
            }
            $this->cart = $this->storageHandler->remove($key);

            $response = $this->ms2->invokeEvent('msOnRemoveFromCart', array('key' => $key, 'cart' => $this));
            if (!$response['success']) {
                return $this->error($response['message']);
            }

            return $this->success('ms2_cart_remove_success', $this->status());
        } else {
            return $this->error('ms2_cart_remove_error');
        }
    }


    /**
     * @param string $key
     * @param int $count
     *
     * @return array|string
     */
    public function change($key, $count)
    {
        $status = array();
        if (array_key_exists($key, $this->cart)) {
            if ($count <= 0) {
                return $this->remove($key);
            } else {
                if ($count > $this->config['max_count']) {
                    return $this->error('ms2_cart_add_err_count', $this->status(), array('count' => $count));
                } else {
                    $response = $this->ms2->invokeEvent(
                        'msOnBeforeChangeInCart',
                        array('key' => $key, 'count' => $count, 'cart' => $this)
                    );
                    if (!$response['success']) {
                        return $this->error($response['message']);
                    }

                    $count = $response['data']['count'];
                    $this->cart = $this->storageHandler->change($key, $count);
                    $response = $this->ms2->invokeEvent(
                        'msOnChangeInCart',
                        array('key' => $key, 'count' => $count, 'cart' => $this)
                    );
                    if (!$response['success']) {
                        return $this->error($response['message']);
                    }
                    $status['key'] = $key;
                    $status['cost'] = $count * $this->cart[$key]['price'];
                }
            }

            return $this->success(
                'ms2_cart_change_success',
                $this->status($status),
                array('count' => $count)
            );
        } else {
            return $this->error('ms2_cart_change_error', $this->status($status));
        }
    }


    /**
     * @return array|string
     */
    public function clean()
    {
        $response = $this->ms2->invokeEvent('msOnBeforeEmptyCart', array('cart' => $this));
        if (!$response['success']) {
            return $this->error($response['message']);
        }

        $this->cart = $this->storageHandler->clean($this->ctx);

        $response = $this->ms2->invokeEvent('msOnEmptyCart', array('cart' => $this));
        if (!$response['success']) {
            return $this->error($response['message']);
        }

        return $this->success('ms2_cart_clean_success', $this->status());
    }


    /**
     * @param array $data
     *
     * @return array
     */
    public function status($data = array())
    {
        $status = array(
            'total_count' => 0,
            'total_cost' => 0,
            'total_weight' => 0,
            'total_discount' => 0,
            'total_positions' => count($this->cart),
        );
        foreach ($this->cart as $item) {
            if (empty($item['ctx']) || $item['ctx'] == $this->ctx) {
                $status['total_count'] += $item['count'];
                $status['total_cost'] += $item['price'] * $item['count'];
                $status['total_weight'] += $item['weight'] * $item['count'];
                $status['total_discount'] += $item['discount_price'] * $item['count'];
            }
        }

        $status = array_merge($data, $status);

        $response = $this->ms2->invokeEvent('msOnGetStatusCart', array(
            'status' => $status,
            'cart' => $this,
        ));
        if ($response['success']) {
            $status = $response['data']['status'];
        }

        return $status;
    }


    /**
     * @return array
     */
    public function get()
    {
        $cart = array();
        foreach ($this->cart as $key => $item) {
            if (empty($item['ctx']) || $item['ctx'] == $this->ctx) {
                $cart[$key] = $item;
            }
        }

        return $cart;
    }


    /**
     * @param array $cart
     */
    public function set($cart = array())
    {
        $this->cart = $this->storageHandler->set($cart);
    }

    /**
     * Set controller for Cart
     */
    protected function storageInit()
    {
        switch ($this->storage) {
            case 'session':
                require_once dirname(__FILE__) . '/storage/session/cartsessionhandler.class.php';
                $this->storageHandler = new CartSessionHandler($this->modx);
                break;
            case 'db':
                require_once dirname(__FILE__) . '/storage/db/cartdbhandler.class.php';
                $this->storageHandler = new CartDBHandler($this->modx, $this->ms2);
                break;
        }
    }


    /**
     * Shorthand for MS2 error method
     *
     * @param string $message
     * @param array $data
     * @param array $placeholders
     *
     * @return array|string
     */
    public function error($message = '', $data = array(), $placeholders = array())
    {
        return $this->ms2->error($message, $data, $placeholders);
    }


    /**
     * Shorthand for MS2 success method
     *
     * @param string $message
     * @param array $data
     * @param array $placeholders
     *
     * @return array|string
     */
    public function success($message = '', $data = array(), $placeholders = array())
    {
        return $this->ms2->success($message, $data, $placeholders);
    }
}
