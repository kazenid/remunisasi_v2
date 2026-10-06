<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // Pass product data to dashboard widgets via Inertia props
        $products = Product::orderBy('price', 'desc')->take(10)->get();

        return Inertia::render('Dashboard', [
            'products' => $products,
        ]);
    }
}
