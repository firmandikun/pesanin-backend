<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Reorder;
use Illuminate\Http\Request;

/** Pelanggan → Waktunya Beli Lagi. */
class ReorderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $rows = array_map(fn ($r) => $r + ['message' => Reorder::message($user, $r)], Reorder::due($user));

        return ['settings' => Reorder::settings($user), 'default_message' => Reorder::DEFAULT_MESSAGE, 'customers' => $rows];
    }

    public function contacted(Request $request)
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s-]{6,20}$/'], 'name' => 'nullable|string|max:120']);
        Reorder::markContacted($request->user(), $d['phone'], $d['name'] ?? null);

        return response()->noContent();
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['days' => 'required|integer|min:7|max:180', 'message' => 'nullable|string|max:1000']);
        $user = $request->user();
        $user->update(['settings' => array_merge($user->settings ?? [], [Reorder::KEY => ['days' => $d['days'], 'message' => trim((string) ($d['message'] ?? ''))]])]);

        return Reorder::settings($user->fresh());
    }
}
