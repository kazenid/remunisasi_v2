<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Demo Admin User ─────────────────────────────────────────────
        User::firstOrCreate(
            ['email' => 'admin@sakai.dev'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
            ]
        );

        // ─── Demo Products ───────────────────────────────────────────────
        $products = [
            ['name' => 'Bamboo Watch',         'description' => 'A stylish eco-friendly bamboo watch.', 'image' => 'bamboo-watch.jpg',         'price' => 65,   'category' => 'Accessories', 'quantity' => 24,  'inventory_status' => 'INSTOCK',    'rating' => 5],
            ['name' => 'Black Watch',           'description' => 'Elegant black watch for formal wear.', 'image' => 'black-watch.jpg',          'price' => 72,   'category' => 'Accessories', 'quantity' => 61,  'inventory_status' => 'INSTOCK',    'rating' => 4],
            ['name' => 'Blue Band',             'description' => 'Comfortable blue fitness band.',       'image' => 'blue-band.jpg',            'price' => 79,   'category' => 'Fitness',     'quantity' => 2,   'inventory_status' => 'LOWSTOCK',   'rating' => 3],
            ['name' => 'Blue T-Shirt',          'description' => 'Casual blue t-shirt.',                'image' => 'blue-t-shirt.jpg',         'price' => 29,   'category' => 'Clothing',    'quantity' => 25,  'inventory_status' => 'INSTOCK',    'rating' => 5],
            ['name' => 'Bracelet',              'description' => 'Beautiful handmade bracelet.',         'image' => 'bracelet.jpg',             'price' => 15,   'category' => 'Accessories', 'quantity' => 73,  'inventory_status' => 'INSTOCK',    'rating' => 4],
            ['name' => 'Brown Purse',           'description' => 'Leather brown purse.',                'image' => 'brown-purse.jpg',          'price' => 120,  'category' => 'Accessories', 'quantity' => 0,   'inventory_status' => 'OUTOFSTOCK', 'rating' => 4],
            ['name' => 'Chakra Bracelet',       'description' => 'Healing chakra bracelet.',            'image' => 'chakra-bracelet.jpg',      'price' => 32,   'category' => 'Accessories', 'quantity' => 5,   'inventory_status' => 'LOWSTOCK',   'rating' => 3],
            ['name' => 'Galaxy Earrings',       'description' => 'Sparkling galaxy-themed earrings.',   'image' => 'galaxy-earrings.jpg',      'price' => 34,   'category' => 'Accessories', 'quantity' => 23,  'inventory_status' => 'INSTOCK',    'rating' => 5],
            ['name' => 'Game Controller',       'description' => 'Wireless gaming controller.',          'image' => 'game-controller.jpg',     'price' => 99,   'category' => 'Electronics', 'quantity' => 50,  'inventory_status' => 'INSTOCK',    'rating' => 4],
            ['name' => 'Gaming Set',            'description' => 'Complete gaming peripheral set.',      'image' => 'gaming-set.jpg',          'price' => 299,  'category' => 'Electronics', 'quantity' => 10,  'inventory_status' => 'LOWSTOCK',   'rating' => 3],
            ['name' => 'Gold Phone Case',       'description' => 'Luxury gold phone case.',             'image' => 'gold-phone-case.jpg',     'price' => 24,   'category' => 'Electronics', 'quantity' => 34,  'inventory_status' => 'INSTOCK',    'rating' => 4],
            ['name' => 'Green Earbuds',         'description' => 'Wireless earbuds in forest green.',   'image' => 'green-earbuds.jpg',       'price' => 89,   'category' => 'Electronics', 'quantity' => 23,  'inventory_status' => 'INSTOCK',    'rating' => 4],
            ['name' => 'Green T-Shirt',         'description' => 'Comfortable casual green t-shirt.',   'image' => 'green-t-shirt.jpg',       'price' => 49,   'category' => 'Clothing',    'quantity' => 74,  'inventory_status' => 'INSTOCK',    'rating' => 5],
            ['name' => 'Grey T-Shirt',          'description' => 'Classic grey casual t-shirt.',        'image' => 'grey-t-shirt.jpg',        'price' => 48,   'category' => 'Clothing',    'quantity' => 0,   'inventory_status' => 'OUTOFSTOCK', 'rating' => 3],
            ['name' => 'Headphones',            'description' => 'Premium noise-cancelling headphones.','image' => 'headphones.jpg',          'price' => 175,  'category' => 'Electronics', 'quantity' => 8,   'inventory_status' => 'LOWSTOCK',   'rating' => 5],
        ];

        foreach ($products as $product) {
            Product::firstOrCreate(['name' => $product['name']], $product);
        }
    }
}
