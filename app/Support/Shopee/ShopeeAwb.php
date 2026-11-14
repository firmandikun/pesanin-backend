<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use Illuminate\Support\Collection;

/**
 * Downloads Shopee air waybill PDFs (orders or "Pengiriman Kilat" reservations) for ONE shop and ONE courier,
 * as Shopee requires. Shared by the manual print and the scheduled Telegram send. Throws ShopeeException.
 */
class ShopeeAwb
{
    public function __construct(private ShopeeClient $api = new ShopeeClient) {}

    /** @param  Collection  $orders  orders with tracking_number and external_order_sn */
    public function orders(MarketplaceShop $shop, Collection $orders): string
    {
        $sns = $orders->pluck('external_order_sn')->all();
        $this->api->createShippingDocument($shop, $orders->pluck('tracking_number', 'external_order_sn')->all());
        $this->wait(fn () => $this->api->shippingDocumentResult($shop, $sns));

        return $this->api->downloadShippingDocument($shop, $sns);
    }

    /** @param  Collection  $bookings  bookings with tracking_number and booking_sn */
    public function bookings(MarketplaceShop $shop, Collection $bookings): string
    {
        $sns = $bookings->pluck('booking_sn')->all();
        $this->api->createBookingShippingDocument($shop, $bookings->pluck('tracking_number', 'booking_sn')->all());
        $this->wait(fn () => $this->api->bookingShippingDocumentResult($shop, $sns));

        return $this->api->downloadBookingShippingDocument($shop, $sns);
    }

    private function wait(callable $result): void
    {
        for ($i = 0; $i < 6; $i++) {
            if (! collect($result())->where('status', 'PROCESSING')->count()) {
                return;
            }
            usleep(800_000);
        }
    }
}
