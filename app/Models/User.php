<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'store_name', 'store_slug', 'email', 'whatsapp', 'bank_accounts', 'cod_fee_percent', 'settings', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'bank_accounts' => 'array',
            'cod_fee_percent' => 'float',
            'settings' => 'array',
            'plan_expires_at' => 'datetime',
            'plan_auto_renew' => 'boolean',
        ];
    }

    /** @var list<string> */
    protected $appends = ['active_plan'];

    /** Plan in effect right now (Gratis once a paid period has lapsed). */
    public function getActivePlanAttribute(): string
    {
        return \App\Support\Plans::current($this);
    }

    public function marketplaceShops(): HasMany
    {
        return $this->hasMany(MarketplaceShop::class);
    }

    public function adSpends(): HasMany
    {
        return $this->hasMany(AdSpend::class);
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function landingPages(): HasMany
    {
        return $this->hasMany(LandingPage::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** Default pickup address used as shipping origin. */
    public function pickupAddress(): ?Address
    {
        return $this->addresses()->with('region')->orderByDesc('is_default')->oldest()->first();
    }

    public function followupTemplates(): HasMany
    {
        return $this->hasMany(FollowupTemplate::class);
    }

    public function bannedDevices(): HasMany
    {
        return $this->hasMany(BannedDevice::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function integrations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserIntegration::class);
    }

    /** The seller's connected Biteship / Midtrans account, or null. */
    public function integration(string $provider): ?UserIntegration
    {
        return $this->integrations->first(fn ($i) => $i->provider === $provider && $i->status === 'active');
    }
}
