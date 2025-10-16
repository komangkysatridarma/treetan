<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Xendit\Configuration;
use Xendit\Invoice\InvoiceApi;
use Xendit\Invoice\CreateInvoiceRequest;

class PaymentController extends Controller
{
    private $invoiceApi;

    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
        $this->invoiceApi = new InvoiceApi();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string|in:CREDIT_CARD,BCA,BNI,MANDIRI,PERMATA,BRI,OVO,DANA,LINKAJA,QRIS',
        ]);

        $order = Order::with('orderItems.product', 'user')->findOrFail($validated['order_id']);

        $existingPayment = Payment::where('order_id', $order->id)->first();
        if ($existingPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Order already has payment',
                'data' => [
                    'payment_id' => $existingPayment->id,
                    'status' => $existingPayment->status
                ]
            ], 400);
        }

        if ($order->status !== 'PENDING_PAYMENT') {
            return response()->json([
                'success' => false,
                'message' => 'Order is not eligible for payment'
            ], 400);
        }

        $externalId = 'INV-' . $order->order_number . '-' . time();

        try {
            $items = [];
            foreach ($order->orderItems as $item) {
                $items[] = [
                    'name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'category' => 'product'
                ];
            }

            $invoiceData = new CreateInvoiceRequest([
                'external_id' => $externalId,
                'amount' => (float) $order->total_amount,
                'payer_email' => $order->user->email,
                'description' => "Payment for Order #{$order->order_number}",
                'invoice_duration' => 86400, 
                'currency' => 'IDR',
                'items' => $items,
                'success_redirect_url' => config('app.url') . '/payment/success',
                'failure_redirect_url' => config('app.url') . '/payment/failed',
                'payment_methods' => [$validated['payment_method']]
            ]);

            $invoice = $this->invoiceApi->createInvoice($invoiceData);

            $payment = Payment::create([
                'order_id' => $order->id,
                'pg_transaction_id' => $invoice['id'],
                'amount' => $order->total_amount,
                'method' => $validated['payment_method'],
                'status' => 'PENDING',
                'raw_response' => $invoice
            ]);

            $order->update([
                'payment_method' => $validated['payment_method']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'order_number' => $order->order_number,
                    'invoice_id' => $invoice['id'],
                    'invoice_url' => $invoice['invoice_url'],
                    'external_id' => $externalId,
                    'amount' => $invoice['amount'],
                    'status' => $invoice['status'],
                    'expiry_date' => $invoice['expiry_date']
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        
        $payments = Payment::with(['order.orderItems.product', 'order.user'])
            ->whereHas('order', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    public function show($id)
    {
        $user = Auth::user();
        
        $payment = Payment::with('order')
            ->whereHas('order', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->find($id);
        
        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        try {
            $invoice = $this->invoiceApi->getInvoiceById($payment->pg_transaction_id);
            $payment->update([
                'status' => $this->mapXenditStatus($invoice['status']),
                'raw_response' => $invoice
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'order_number' => $payment->order->order_number,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'invoice_url' => $invoice['invoice_url'] ?? null,
                    'paid_at' => $payment->paid_at,
                    'created_at' => $payment->created_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function webhook(Request $request)
    {
        $webhookToken = $request->header('x-callback-token');
        
        if ($webhookToken !== config('services.xendit.webhook_token')) {
            Log::warning('Invalid webhook token received');
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook token'
            ], 401);
        }
        $payload = $request->all();
        Log::info('Xendit Webhook Received', $payload);

        try {
            $payment = Payment::where('pg_transaction_id', $payload['id'])->first();

            if (!$payment) {
                Log::warning('Payment not found for invoice: ' . $payload['id']);
                return response()->json(['success' => false], 404);
            }

            $paymentStatus = $this->mapXenditStatus($payload['status']);

            $payment->update([
                'status' => $paymentStatus,
                'paid_at' => $paymentStatus === 'SUCCESS' ? now() : null,
                'raw_response' => $payload
            ]);

            $order = $payment->order;
            if ($paymentStatus === 'SUCCESS') {
                $order->update([
                    'status' => 'PAID'
                ]);

                Log::info("Order #{$order->order_number} payment successful");
            }
            if ($paymentStatus === 'FAILED' || $paymentStatus === 'EXPIRED') {
                $order->update([
                    'status' => 'CANCELLED'
                ]);

                Log::info("Order #{$order->order_number} payment {$paymentStatus}");
            }

            return response()->json(['success' => true], 200);

        } catch (\Exception $e) {
            Log::error('Webhook Error: ' . $e->getMessage(), [
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    private function mapXenditStatus($xenditStatus)
    {
        $statusMap = [
            'PENDING' => 'PENDING',
            'PAID' => 'SUCCESS',
            'SETTLED' => 'SUCCESS',
            'EXPIRED' => 'EXPIRED',
            'FAILED' => 'FAILED'
        ];

        return $statusMap[$xenditStatus] ?? 'PENDING';
    }

    public function destroy($id)
    {
        $user = Auth::user();
        
        $payment = Payment::with('order')
            ->whereHas('order', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->find($id);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        if ($payment->status === 'SUCCESS') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel successful payment'
            ], 400);
        }

        try {
            $this->invoiceApi->expireInvoice($payment->pg_transaction_id);

            $payment->update(['status' => 'EXPIRED']);
            $payment->order->update(['status' => 'CANCELLED']);

            return response()->json([
                'success' => true,
                'message' => 'Payment cancelled successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}