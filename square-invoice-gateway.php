<?php
/**
 * Plugin Name: Square Invoice Gateway
 * Description: Automatically generates a Square invoice when a customer places an order
 * Version: 1.0
 */

add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_Square_Invoice_Gateway';
    return $gateways;
});

add_action('plugins_loaded', function() {
    class WC_Square_Invoice_Gateway extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'square_invoice';
            $this->method_title       = 'Square Invoice';
            $this->method_description = 'Auto-generates a Square invoice and emails the customer.';
            $this->has_fields         = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->access_token = $this->get_option('access_token');
            $this->location_id  = $this->get_option('location_id');
            $this->sandbox      = $this->get_option('sandbox') === 'yes';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id,
                [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled'      => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
                'title'        => ['title' => 'Title', 'type' => 'text', 'default' => 'Invoice (Credit/Debit Card or Apple Pay)'],
                'description'  => ['title' => 'Description', 'type' => 'textarea',
                    'default' => 'Complete your order and you will be directed to a secure payment page. A copy of the invoice will be sent to your email.'],
                'access_token' => ['title' => 'Square Access Token', 'type' => 'password'],
                'location_id'  => ['title' => 'Square Location ID', 'type' => 'text'],
                'sandbox'      => ['title' => 'Sandbox Mode (disable when ready to go live)', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $base_url = $this->sandbox
                ? 'https://connect.squareupsandbox.com'
                : 'https://connect.squareup.com';

            $customer_id = $this->get_or_create_customer($order, $base_url);
            if (!$customer_id) {
                wc_add_notice('Could not create customer record. Please try again.', 'error');
                return;
            }

            $square_order_id = $this->create_square_order($order, $base_url);
            if (!$square_order_id) {
                wc_add_notice('Could not create order. Please try again.', 'error');
                return;
            }

            $invoice_url = $this->create_and_publish_invoice($order, $customer_id, $square_order_id, $base_url);
            if (!$invoice_url) {
                wc_add_notice('Could not generate invoice. Please try again.', 'error');
                return;
            }

            $order->update_status('pending', 'Square invoice sent to customer.');
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $invoice_url,
            ];
        }

        private function get_or_create_customer($order, $base_url) {
            $response = wp_remote_post("$base_url/v2/customers", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'given_name'      => $order->get_billing_first_name(),
                    'family_name'     => $order->get_billing_last_name(),
                    'email_address'   => $order->get_billing_email(),
                    'phone_number'    => $order->get_billing_phone(),
                    'idempotency_key' => 'customer-' . $order->get_id() . '-' . time(),
                ]),
            ]);

            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $body['customer']['id'] ?? null;
        }

        private function create_square_order($order, $base_url) {
            $line_items = [];
            foreach ($order->get_items() as $item) {
                $line_items[] = [
                    'name'     => $item->get_name(),
                    'quantity' => (string) $item->get_quantity(),
                    'base_price_money' => [
                        'amount'   => round(($item->get_total() / $item->get_quantity()) * 100),
                        'currency' => get_woocommerce_currency(),
                    ],
                ];
            }

            $response = wp_remote_post("$base_url/v2/orders", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'idempotency_key' => 'order-' . $order->get_id() . '-' . time(),
                    'order' => [
                        'location_id' => $this->location_id,
                        'line_items'  => $line_items,
                    ],
                ]),
            ]);

            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $body['order']['id'] ?? null;
        }

        private function create_and_publish_invoice($order, $customer_id, $square_order_id, $base_url) {
            $response = wp_remote_post("$base_url/v2/invoices", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'idempotency_key' => 'invoice-' . $order->get_id() . '-' . time(),
                    'invoice' => [
                        'location_id' => $this->location_id,
                        'order_id'    => $square_order_id,
                        'primary_recipient' => ['customer_id' => $customer_id],
                        'payment_requests'  => [[
                            'request_type' => 'BALANCE',
                            'due_date'     => date('Y-m-d', strtotime('+1 day')),
                            'automatic_payment_source' => 'NONE',
                        ]],
                        'delivery_method' => 'EMAIL',
                        'invoice_number'  => (string) $order->get_id(),
                        'accepted_payment_methods' => [
                            'card'             => true,
                            'square_gift_card' => false,
                            'bank_account'     => false,
                            'buy_now_pay_later' => false,
                            'cash_app_pay'     => false,
                        ],
                    ],
                ]),
            ]);

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $invoice_id      = $body['invoice']['id'] ?? null;
            $invoice_version = $body['invoice']['version'] ?? null;

            if (!$invoice_id) return null;

            $pub_response = wp_remote_post("$base_url/v2/invoices/$invoice_id/publish", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode([
                    'version'         => $invoice_version,
                    'idempotency_key' => 'publish-' . $order->get_id() . '-' . time(),
                ]),
            ]);

            $pub_body = json_decode(wp_remote_retrieve_body($pub_response), true);
            return $pub_body['invoice']['public_url'] ?? null;
        }
    }
});
