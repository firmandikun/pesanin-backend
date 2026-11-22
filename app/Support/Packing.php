<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * "Cek isi paket" in Scan Out: after the resi is scanned, every product of the order is scanned (SKU) or ticked,
 * a photo of the open parcel is taken, then it is done. The photo is kept on the order as proof when a buyer
 * disputes ("barang kurang / salah kirim") and shown on the order detail page.
 */
class Packing
{
    public static function photoUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }

    /** The order as the packing check needs it: products to put in the parcel. */
    public static function row(Order $o): array
    {
        $items = collect($o->items ?? []);
        if ($items->isEmpty()) { // own-store orders: one product line
            $items = collect([['name' => $o->product?->name ?? 'Produk', 'variant' => $o->variant, 'sku' => null, 'qty' => (int) ($o->quantity ?: 1), 'image' => null]]);
        }

        return [
            'id' => $o->id, 'code' => $o->code, 'order_sn' => $o->external_order_sn, 'resi' => $o->tracking_number,
            'note' => $o->external_note ?: $o->notes,
            'items' => $items->map(fn ($i) => [
                'name' => (string) ($i['name'] ?? ''), 'variant' => $i['variant'] ?? null, 'sku' => ($i['sku'] ?? null) ?: ($i['model_sku'] ?? null) ?: ($i['item_sku'] ?? null),
                'qty' => max(1, (int) ($i['qty'] ?? 1)), 'image' => $i['image'] ?? null,
            ])->values(),
            'packed_at' => $o->packed_at?->toIso8601String(), 'packed_by' => $o->packed_by,
        ];
    }

    /** Rules for the "done" request (photo + scan result as JSON). */
    public const RULES = ['photo' => 'nullable|image|max:8192', 'check' => 'nullable|string|max:5000'];

    /** Save the packing check of an order. */
    public static function save(Order $order, ?UploadedFile $photo, ?string $checkJson, ?string $by): array
    {
        $check = json_decode((string) $checkJson, true);
        $check = collect(is_array($check) ? $check : [])->take(100)->map(fn ($c) => [
            'name' => mb_substr((string) ($c['name'] ?? ''), 0, 200), 'sku' => mb_substr((string) ($c['sku'] ?? ''), 0, 100) ?: null,
            'qty' => (int) ($c['qty'] ?? 0), 'packed' => (int) ($c['packed'] ?? 0), 'scanned' => (bool) ($c['scanned'] ?? false),
        ])->values()->all();
        $path = $order->packing_photo;
        if ($photo) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            $path = $photo->store('packing/'.$order->user_id, 'public');
        }
        $order->forceFill(['packed_at' => now(), 'packed_by' => $by, 'packing_photo' => $path, 'packing_check' => $check])->save();
        ActivityLog::record($order->user, 'order.packed', "Cek isi paket {$order->code}".($by ? " oleh {$by}" : ''));

        return ['packed_at' => $order->packed_at->toIso8601String(), 'photo' => self::photoUrl($path)];
    }
}
