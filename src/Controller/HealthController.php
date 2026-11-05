<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

/**
 * Controller providing public health check endpoint for monitoring application status.
 */
class HealthController extends AbstractController
{
    /**
     * Returns application and database operational status.
     *
     * @Route("/health", name="app_health", methods={"GET"})
     *
     * @param ManagerRegistry $doctrine
     *
     * @return JsonResponse
     */
    public function check(ManagerRegistry $doctrine): JsonResponse
    {
        $dbStatus = 'UP';
        try {
            $connection = $doctrine->getConnection();
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            $dbStatus = 'DOWN';
        }

        $overallStatus = ($dbStatus === 'UP') ? 'UP' : 'DOWN';
        $httpCode      = ($overallStatus === 'UP') ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        $payload = [
            'status'    => $overallStatus,
            'database'  => $dbStatus,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        return new JsonResponse($payload, $httpCode);
    }
}
