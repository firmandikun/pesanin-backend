<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Biteship was removed (RajaOngkir only): keep old rows for the record but switch them off. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_integrations')->where('provider', 'biteship')->update(['status' => 'disabled']);
    }

    public function down(): void
    {
        DB::table('user_integrations')->where('provider', 'biteship')->where('status', 'disabled')->update(['status' => 'active']);
    }
};
