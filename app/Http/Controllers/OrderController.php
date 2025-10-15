<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index()
    {
        return Order::with('orderItems', 'user')->get();
    }

    public function show($id)
    {
        $order = Order::with('orderItems', 'user')->find($id);
        return $order ? response()->json($order) : response()->json(['message' => 'Order not found'], 404);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'shipping_address' => 'required|string',
            'total_amount' => 'required|numeric',
            'payment_method' => 'nullable|string'
        ]);

        $validated['order_number'] = 'ORD-' . strtoupper(Str::random(10));

        $order = Order::create($validated);
        return response()->json($order, 201);
    }

    public function update(Request $request, $id)
    {
        $order = Order::find($id);
        if (!$order) return response()->json(['message' => 'Order not found'], 404);

        $order->update($request->all());
        return response()->json($order);
    }

    public function destroy($id)
    {
        $order = Order::find($id);
        if (!$order) return response()->json(['message' => 'Order not found'], 404);

        $order->delete();
        return response()->json(['message' => 'Order deleted']);
    }
}
