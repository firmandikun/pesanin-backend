<?php

namespace Database\Seeders;

use App\Models\ChatLabel;
use App\Models\QuickReply;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for the inbox: one team member per role (with a login), example labels and quick replies.
 * Safe to run again (updates by email / name / shortcut).
 *
 *   php artisan db:seed --class=InboxDemoSeeder
 *   DEMO_OWNER_EMAIL=you@example.com php artisan db:seed --class=InboxDemoSeeder
 *
 * Demo logins (local testing only, change or delete before production):
 *   cs.sari@pesanin.test / cs.budi@pesanin.test / gudang.rina@pesanin.test / iklan.andi@pesanin.test
 *   password: pesanin-demo
 */
class InboxDemoSeeder extends Seeder
{
    public const PASSWORD = 'pesanin-demo';

    public function run(): void
    {
        $owner = env('DEMO_OWNER_EMAIL') ? User::where('email', env('DEMO_OWNER_EMAIL'))->firstOrFail() : User::orderBy('id')->firstOrFail();

        $team = [
            ['Sari Wulandari', 'cs.sari@pesanin.test', '081200000001', 'cs'],
            ['Budi Santoso', 'cs.budi@pesanin.test', '081200000002', 'cs'],
            ['Rina Gudang', 'gudang.rina@pesanin.test', '081200000003', 'gudang'],
            ['Andi Iklan', 'iklan.andi@pesanin.test', '081200000004', 'advertiser'],
        ];
        foreach ($team as [$name, $email, $phone, $role]) {
            $m = TeamMember::firstOrNew(['user_id' => $owner->id, 'email' => $email]);
            $m->user_id = $owner->id;
            $m->fill(['name' => $name, 'phone' => $phone, 'role' => $role, 'is_active' => true, 'accept_orders' => $role === 'cs', 'landing_access' => $role === 'advertiser', 'password' => self::PASSWORD])->save();
        }

        foreach ([['Calon pembeli', 'blue'], ['Sudah transfer', 'green'], ['Komplain', 'red'], ['Reseller', 'violet'], ['VIP', 'amber']] as [$name, $color]) {
            $l = ChatLabel::firstOrNew(['user_id' => $owner->id, 'name' => $name]);
            $l->user_id = $owner->id;
            $l->fill(['color' => $color])->save();
        }

        $replies = [
            ['salam', 'Sapaan', 'Halo kak {nama} 👋 Terima kasih sudah menghubungi kami. Ada yang bisa dibantu?'],
            ['rekening', 'Pembayaran', "Pembayaran bisa ke rekening berikut ya kak {nama}:\nBCA 1234567890 a.n. Toko Demo\nSetelah transfer, mohon kirim bukti transfernya 🙏"],
            ['ongkir', 'Pengiriman', 'Untuk cek ongkir, boleh kirim alamat lengkap (kecamatan & kota) ya kak. Kami kirim pakai JNE, J&T, SiCepat, atau COD.'],
            ['resi', 'Pengiriman', 'Pesanan kakak sudah dikirim ✅ Nomor resinya: [isi resi]. Bisa dicek di aplikasi kurir ya.'],
            ['cod', 'Pembayaran', 'Bisa COD kak, bayar di tempat saat paket sampai. Ada tambahan biaya COD sesuai kurir.'],
            ['terimakasih', 'Penutup', 'Terima kasih kak {nama} sudah belanja 🙏 Kalau puas, ditunggu order berikutnya ya!'],
        ];
        foreach ($replies as [$shortcut, $category, $body]) {
            $r = QuickReply::firstOrNew(['user_id' => $owner->id, 'shortcut' => $shortcut]);
            $r->user_id = $owner->id;
            $r->fill(['category' => $category, 'body' => $body])->save();
        }

        $this->command?->info("Demo inbox data for {$owner->email}: ".count($team).' team members, labels and quick replies.');
    }
}
