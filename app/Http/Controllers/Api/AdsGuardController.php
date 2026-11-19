<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AdsGuard;
use App\Support\Shopee\ShopeeException;
use Illuminate\Http\Request;

/** Marketplace → Iklan → Penjaga Iklan: break-even check per ad, and pause / resume / raise ROAS target. */
class AdsGuardController extends Controller
{
    public function index(Request $request)
    {
        $days = $request->integer('days') ?: null;

        return AdsGuard::evaluate($request->user(), in_array($days, [3, 7, 14, 30], true) ? $days : null);
    }

    public function action(Request $request, int $campaign)
    {
        $d = $request->validate(['action' => 'required|in:pause,resume,change_roas_target', 'target' => 'required_if:action,change_roas_target|nullable|numeric|min:0.5|max:100']);
        try {
            $c = AdsGuard::act($request->user(), $campaign, $d['action'], isset($d['target']) ? (float) $d['target'] : null);
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak perubahan iklan: '.$e->getMessage());
        }

        return ['id' => $c->id, 'status' => $c->status, 'roas_target' => $c->roas_target ? (float) $c->roas_target : null];
    }
}
