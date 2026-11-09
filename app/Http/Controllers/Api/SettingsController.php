<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\BannedDevice;
use App\Models\FollowupTemplate;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function addresses(Request $request)
    {
        return $request->user()->addresses()->with('region')->orderByDesc('is_default')->latest()->get();
    }

    public function saveAddress(Request $request, ?Address $address = null)
    {
        abort_if($address && $address->user_id !== $request->user()->id, 404);
        $data = $request->validate([
            'label' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:30',
            'address' => 'required|string',
            'region_id' => 'required|exists:regions,id',
            'postal_code' => 'nullable|string|max:10',
            'is_default' => 'boolean',
        ]);
        $data['city'] = \App\Models\Region::find($data['region_id'])->city;
        if (! empty($data['is_default']) || ! $request->user()->addresses()->exists()) {
            $data['is_default'] = true;
            $request->user()->addresses()->update(['is_default' => false]);
        }
        $address = $address
            ? tap($address)->update($data)
            : $request->user()->addresses()->create($data);
        ActivityLog::record($request->user(), 'address.save', "Menyimpan alamat {$address->label}");

        return $address->load('region');
    }

    public function deleteAddress(Request $request, Address $address)
    {
        abort_if($address->user_id !== $request->user()->id, 404);
        $address->delete();

        return response()->noContent();
    }

    public function templates(Request $request)
    {
        return $request->user()->followupTemplates()->orderBy('id')->get();
    }

    public function updateTemplate(Request $request, FollowupTemplate $template)
    {
        abort_if($template->user_id !== $request->user()->id, 404);
        $template->update($request->validate([
            'name' => 'required|string|max:100',
            'message' => 'required|string|max:2000',
            'is_active' => 'boolean',
        ]));
        ActivityLog::record($request->user(), 'followup.update', "Mengubah template {$template->name}");

        return $template;
    }

    public function createTemplate(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'message' => 'required|string|max:2000']);
        $t = $request->user()->followupTemplates()->create($data + ['event' => 'custom_'.Str::lower(Str::random(6))]);
        ActivityLog::record($request->user(), 'followup.create', "Menambah template {$t->name}");

        return response()->json($t, 201);
    }

    public function deleteTemplate(Request $request, FollowupTemplate $template)
    {
        abort_if($template->user_id !== $request->user()->id || ! str_starts_with($template->event, 'custom_'), 404);
        $template->delete();

        return response()->noContent();
    }

    public function banned(Request $request)
    {
        return $request->user()->bannedDevices()->latest()->get();
    }

    public function ban(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:ip,phone',
            'value' => 'required|string|max:64',
            'reason' => 'nullable|string|max:200',
        ]);
        if ($data['type'] === 'phone') {
            $data['value'] = WhatsApp::normalize($data['value']);
        }
        $device = $request->user()->bannedDevices()->firstOrCreate(
            ['type' => $data['type'], 'value' => $data['value']],
            ['reason' => $data['reason'] ?? null],
        );
        ActivityLog::record($request->user(), 'banned.create', "Blokir {$data['type']} {$data['value']}");

        return $device;
    }

    public function unban(Request $request, BannedDevice $device)
    {
        abort_if($device->user_id !== $request->user()->id, 404);
        $device->delete();

        return response()->noContent();
    }

    public function activity(Request $request)
    {
        return $request->user()->activityLogs()->latest('created_at')->paginate(min(100, max(1, $request->integer('per_page', 10))));
    }
}
