<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultTemplates;
use Illuminate\Database\Seeder;

/**
 * 1 platform admin + 4 seller accounts (one per plan), all with password "password123".
 * php artisan db:seed --class=AccountsSeeder   (safe to run again; updates in place)
 */
class AccountsSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@pesanin.test'], [
            'name' => 'Admin Pesanin', 'store_name' => 'Pesanin Admin', 'store_slug' => 'pesanin-admin', 'password' => self::PASSWORD,
        ]);
        $admin->forceFill(['is_admin' => true, 'suspended_at' => null])->save();

        $sellers = [
            1 => ['gratis', null, 'Rina Kartika', 'Rina Beauty'],
            2 => ['starter', 'monthly', 'Budi Santoso', 'Budi Sparepart Motor'],
            3 => ['bisnis', 'monthly', 'Dewi Lestari', 'Dewi Fashion'],
            4 => ['pro', 'yearly', 'Agus Prayogo', 'Agus Elektronik'],
        ];
        foreach ($sellers as $n => [$plan, $billing, $name, $store]) {
            $u = User::updateOrCreate(['email' => "pengguna{$n}@pesanin.test"], [
                'name' => $name, 'store_name' => $store, 'store_slug' => "pengguna-{$n}", 'whatsapp' => '08120000000'.$n, 'password' => self::PASSWORD,
            ]);
            $u->forceFill([
                'is_admin' => false, 'suspended_at' => null, 'plan' => $plan, 'plan_billing' => $billing, 'plan_auto_renew' => true,
                'plan_expires_at' => $billing ? now()->add($billing === 'yearly' ? '1 year' : '1 month')->endOfDay() : null,
            ])->save();
            if (! $u->followupTemplates()->exists()) {
                DefaultTemplates::seed($u);
            }
        }
    }
}
