<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use PHPUnit\Framework\TestCase;

class PlaceTest extends TestCase
{
    public function testGetFullAddressWithAllComponents(): void
    {
        $place = new Place();
        $place->setStreetNumber('12234');
        $place->setStreetName('N Wolf St');
        $place->setCity('Miami');
        $place->setState('FL');
        $place->setZipCode('33173');
        $place->setCountry('USA');

        $this->assertSame('12234 N Wolf St, Miami, FL 33173, USA', $place->getFullAddress());
    }

    public function testGetFullAddressWithoutStreetNumber(): void
    {
        $place = new Place();
        $place->setStreetName('Ocean Drive');
        $place->setCity('Miami Beach');
        $place->setState('FL');
        $place->setZipCode('33139');
        $place->setCountry('USA');

        $this->assertSame('Ocean Drive, Miami Beach, FL 33139, USA', $place->getFullAddress());
    }

    public function testGetFullAddressEmpty(): void
    {
        $place = new Place();
        $this->assertSame('', $place->getFullAddress());
    }
}
