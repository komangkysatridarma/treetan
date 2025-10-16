<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderItemController extends Controller
{
    public function index()
    {
        return OrderItem::with('product', 'order')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric',
        ]);

        $validated['subtotal'] = $validated['quantity'] * $validated['price'];

        $orderItem = OrderItem::create($validated);
        return response()->json($orderItem, 201);
    }

    public function show($id)
    {
        $orderItem = OrderItem::with('product', 'order')->find($id);
        return $orderItem ? response()->json($orderItem) : response()->json(['message' => 'Order item not found'], 404);
    }

    public function update(Request $request, $id)
    {
        $orderItem = OrderItem::find($id);
        if (!$orderItem) return response()->json(['message' => 'Order item not found'], 404);

        $orderItem->update($request->all());
        return response()->json($orderItem);
    }

    public function destroy($id)
    {
        $orderItem = OrderItem::find($id);
        if (!$orderItem) return response()->json(['message' => 'Order item not found'], 404);

        $orderItem->delete();
        return response()->json(['message' => 'Order item deleted']);
    }
}
