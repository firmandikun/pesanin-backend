<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_and_update_print_settings(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/user-settings', [
            'key' => 'print_settings',
            'value' => [
                'paper_size' => '100x150',
                'sku_font_size' => 9,
                'show_table_border' => true,
                'product_text_mode' => 'sku_first',
            ],
        ]);

        $response->assertOk();
        $this->assertEquals('100x150', $user->fresh()->settings['print_settings']['paper_size']);
        $this->assertEquals(9, $user->fresh()->settings['print_settings']['sku_font_size']);
        $this->assertTrue($user->fresh()->settings['print_settings']['show_table_border']);
        $this->assertEquals('sku_first', $user->fresh()->settings['print_settings']['product_text_mode']);

        $getResponse = $this->actingAs($user)->getJson('/api/user-settings');
        $getResponse->assertOk();
        $getResponse->assertJsonFragment([
            'print_settings' => [
                'paper_size' => '100x150',
                'sku_font_size' => 9,
                'show_table_border' => true,
                'product_text_mode' => 'sku_first',
            ],
        ]);
    }
}
