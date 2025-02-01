<?php

namespace App\Gateways\Tebex;

use LaraPay\Framework\Interfaces\GatewayFoundation;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Payment;
use Illuminate\Http\Request;
use Exception;

class Gateway extends GatewayFoundation
{
    /**
     * Define the gateway identifier. This identifier should be unique. For example,
     * if the gateway name is "PayPal Express", the gateway identifier should be "paypal-express".
     *
     * @var string
     */
    protected string $identifier = 'tebex';

    /**
     * Define the gateway version.
     *
     * @var string
     */
    protected string $version = '1.0.0';

    protected $gateway;

    protected string $api_url = 'https://checkout.tebex.io/api';

    public function config(): array
    {
        return [
            'username' => [
                'label' => 'Tebex Username',
                'description' => 'Enter your Tebex username',
                'type' => 'text',
                'rules' => ['required'],
            ],
            'password' => [
                'label' => 'Tebex Password',
                'description' => 'Enter your Tebex password',
                'type' => 'text',
                'rules' => ['required'],
            ],
            'webhook_key' => [
                'label' => 'Webhook Key',
                'description' => 'Enter your Tebex webhook key',
                'type' => 'text',
                'rules' => ['required'],
            ],
        ];
    }

    public function pay($payment)
    {
        $this->gateway = $payment->gateway;

        $checkout = $this->api('post', '/checkout', [
            'basket' => [
                'custom' => [
                    'payment_id' => $payment->id,
                ],
                'return_url' => $payment->successUrl(),
                'complete_url' => $payment->cancelUrl(),
            ],
            'items' => [
                [
                    'package' => [
                        'name' => $payment->description,
                        'price' => $payment->total(),
                        'metaData' => [
                            'payment_id' => $payment->id,
                        ],
                    ],
                    'type' => 'single',
                ],
            ],
        ]);

        if (!$checkout->successful()) {
            throw new Exception('Failed to create checkout using Tebex API');
        }

        return redirect()->away($checkout['links']['checkout']);
    }

    public function webhook(Request $request)
    {
        // WebHook validation
        if ($request->get('type', 'none') == 'validation.webhook') {
            return response()->json(['id' => $request->get('id')], 200);
        }

        $payment_id = $request->get('subject')['custom']['payment_id'];
        $payment = Payment::find($payment_id);
        $this->gateway = $payment->gateway;

        if ($this->isSignatureValid($request)) {
            // Skip Subscription
            if ($request->get('subject')['recurring_payment_reference'] != null) {
                return response()->json(['success' => 'The event has been canceled, we are waiting for the event from the subscription'], 200);
            }

            if ($request->get('type', 'none') == 'payment.completed') {
                $transaction_id = $request->get('subject')['transaction_id'];
                $status = $request->get('subject')['status']['description'];

                if ($status == 'Complete') {
                    $payment->completed($transaction_id, $request->all());

                    return response()->json(['success' => 'Payment completed successfully'], 200);
                } else {
                    return response()->json(['error' => "Payment status: {$status}"], 403);
                }
            }
        } else {
            return response()->json(['error' => 'WebHook signature error'], 403);
        }
    }

    private function isSignatureValid(Request $request): bool
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Signature', 'empty');
        $calculatedSignature = hash_hmac('sha256', hash('sha256', $payload), $this->gateway->config('webhook_key', 'empty'));

        return hash_equals($calculatedSignature, $signature);
    }

    private function api($method, $endpoint, $data = [])
    {
        return Http::withBasicAuth($this->gateway->config('username'), $this->gateway->config('password'))->asJson()
            ->$method($this->api_url . $endpoint, $data);
    }
}
