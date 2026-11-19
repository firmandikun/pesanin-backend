<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Shopee\ShopeeHealth;
use Illuminate\Http\Request;

/** Marketplace → Kesehatan Akun: Shopee account health of each connected shop. */
class ShopeeHealthController extends Controller
{
    public function index(Request $request)
    {
        $shops = $request->user()->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)
            ->when($request->query('shop'), fn ($q, $id) => $q->whereKey($id))->get();

        return ['shops' => $shops->map(fn ($s) => ShopeeHealth::shop($s, $request->boolean('fresh')))->values()];
    }
}
