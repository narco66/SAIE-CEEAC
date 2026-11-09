<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * PLY-02 : jeton de lecteur à durée de vie bornée, renouvelé avec un court chevauchement ; l'ancien cesse ensuite de valoir.
 */
class DeviceTokenRotationTest extends TestCase
{
    public function test_rotated_token_works_and_the_previous_one_expires_after_the_overlap(): void
    {
        [, $old] = $this->pairedDevice($this->screen('ECR-ROT'));

        $rotated = $this->postJson('/api/v1/device/token/rotate', [], ['Authorization' => "Bearer $old"])->assertOk();
        $new = $rotated->json('token');
        $this->assertNotSame($old, $new);
        $this->assertTrue(Carbon::parse($rotated->json('expires_at'))->isAfter(now()->addDays(89)));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $old"])->assertOk();

        $this->travel(11)->minutes();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $old"])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $new"])->assertOk();
        $this->assertNotNull(PersonalAccessToken::findToken($new)->expires_at);
    }
}
