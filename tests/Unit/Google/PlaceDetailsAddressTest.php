<?php

namespace App\Tests\Unit\Google;

use App\Google\GoogleMap\Place\Services\PlaceDetails\Address;
use PHPUnit\Framework\TestCase;

class PlaceDetailsAddressTest extends TestCase
{
    public function testSetAndGetPlaceIdString(): void
    {
        $address = new Address();
        $this->assertNull($address->getPlaceId());

        $googlePlaceId = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
        $address->setPlaceId($googlePlaceId);

        $this->assertSame($googlePlaceId, $address->getPlaceId());
    }
}
