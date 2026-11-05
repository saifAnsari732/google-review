<?php

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class HealthControllerTest extends TestCase
{
    public function testCheckDatabaseUp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($connection);

        $controller = new HealthController();
        $response = $controller->check($doctrine);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame('UP', $data['status']);
        $this->assertSame('UP', $data['database']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testCheckDatabaseDown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($connection);

        $controller = new HealthController();
        $response = $controller->check($doctrine);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertSame('DOWN', $data['status']);
        $this->assertSame('DOWN', $data['database']);
        $this->assertArrayHasKey('timestamp', $data);
    }
}
