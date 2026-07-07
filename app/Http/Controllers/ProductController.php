<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pages/Crud', [
            'products' => Product::latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'price'            => 'required|numeric|min:0',
            'category'         => 'nullable|string|max:100',
            'quantity'         => 'required|integer|min:0',
            'inventory_status' => 'required|in:INSTOCK,LOWSTOCK,OUTOFSTOCK',
            'rating'           => 'nullable|integer|min:0|max:5',
        ]);

        Product::create($validated);

        return redirect()->route('pages.crud')
            ->with('success', 'Product created successfully.');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'price'            => 'required|numeric|min:0',
            'category'         => 'nullable|string|max:100',
            'quantity'         => 'required|integer|min:0',
            'inventory_status' => 'required|in:INSTOCK,LOWSTOCK,OUTOFSTOCK',
            'rating'           => 'nullable|integer|min:0|max:5',
        ]);

        $product->update($validated);

        return redirect()->route('pages.crud')
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('pages.crud')
            ->with('success', 'Product deleted successfully.');
    }
}
