<?php

namespace App\Tests\Unit\Challenger;

use App\Api\Core\Exception\ApiNormalOperationException;
use App\Api\Review\Model\ReviewModel;
use App\Controller\HealthController;
use App\Core\Cache\FallbackRedisCacheRegistry;
use App\Core\Listener\ExceptionListener;
use App\Entity\Business;
use App\Entity\Place;
use App\Entity\Review;
use App\Google\GoogleMap\Place\Core\Transport\Response as GoogleResponse;
use App\Google\GoogleMap\Place\Core\Transport\Transport;
use App\Google\GoogleMap\Place\Services\PlaceDetails\Address;
use App\Google\GoogleMap\Place\Services\PlaceDetails\AddressComponentSectionsType;
use App\Google\GoogleMap\Place\Services\PlaceDetails\PlaceDetailsService;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Empirical Adversarial Test Suite for Milestone 1:
 * Verifies Place address edge cases, ReviewModel null place handling,
 * PlaceDetailsService exception throw, offline Redis failover,
 * ExceptionListener JSON output in prod, and HealthController status.
 */
class M1RuntimeRobustnessTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
    }

    // =========================================================================
    // Challenge 1: Place Address Edge Cases
    // =========================================================================

    /**
     * @dataProvider placeAddressCombinationsProvider
     */
    public function testPlaceAddressCombinations(
        ?string $streetNumber,
        ?string $streetName,
        ?string $city,
        ?string $state,
        ?string $zipCode,
        ?string $country,
        string $expectedAddress
    ): void {
        $place = new Place();
        if ($streetNumber !== null) {
            $place->setStreetNumber($streetNumber);
        }
        if ($streetName !== null) {
            $place->setStreetName($streetName);
        }
        if ($city !== null) {
            $place->setCity($city);
        }
        if ($state !== null) {
            $place->setState($state);
        }
        if ($zipCode !== null) {
            $place->setZipCode($zipCode);
        }
        if ($country !== null) {
            $place->setCountry($country);
        }

        $this->assertSame($expectedAddress, $place->getFullAddress());
    }

    public function placeAddressCombinationsProvider(): array
    {
        return [
            'empty_place' => [null, null, null, null, null, null, ''],
            'only_street_number' => ['123', null, null, null, null, null, '123'],
            'only_street_name' => [null, 'Main Street', null, null, null, null, 'Main Street'],
            'only_city' => [null, null, 'Miami', null, null, null, 'Miami'],
            'only_state' => [null, null, null, 'FL', null, null, 'FL'],
            'only_zip_code' => [null, null, null, null, '33173', null, '33173'],
            'only_country' => [null, null, null, null, null, 'USA', 'USA'],
            'street_number_and_name' => ['12234', 'N Wolf St', null, null, null, null, '12234 N Wolf St'],
            'street_name_and_city' => [null, 'Ocean Drive', 'Miami Beach', null, null, null, 'Ocean Drive, Miami Beach'],
            'city_and_state' => [null, null, 'Miami', 'FL', null, null, 'Miami, FL'],
            'state_and_zip' => [null, null, null, 'FL', '33173', null, 'FL 33173'],
            'city_state_zip' => [null, null, 'Miami', 'FL', '33173', null, 'Miami, FL 33173'],
            'missing_street_number' => [null, 'Ocean Drive', 'Miami Beach', 'FL', '33139', 'USA', 'Ocean Drive, Miami Beach, FL 33139, USA'],
            'all_fields_present' => ['12234', 'N Wolf St', 'Miami', 'FL', '33173', 'USA', '12234 N Wolf St, Miami, FL 33173, USA'],
            'partial_number_city_country' => ['500', null, 'Orlando', null, null, 'USA', '500, Orlando, USA'],
            'street_name_and_country' => [null, 'Baker St', null, null, null, 'UK', 'Baker St, UK'],
        ];
    }

    // =========================================================================
    // Challenge 2: ReviewModel Null Place Handling
    // =========================================================================

    public function testReviewModelWithNullPlaceReturnsNullAddressWithoutException(): void
    {
        $business = $this->createMock(Business::class);
        $business->method('getId')->willReturn(10);
        $business->method('getEmail')->willReturn('biz@example.com');
        $business->method('getPlace')->willReturn(null); // Place association is null

        $review = $this->createMock(Review::class);
        $review->method('getId')->willReturn(42);
        $review->method('getName')->willReturn('Acme Review');
        $review->method('getLink')->willReturn('https://google.com/review/42');
        $review->method('getBusiness')->willReturn($business);
        $review->method('getQrCodeImgFilename')->willReturn('non_existent_qr.png');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(function (string $route, array $params) {
            return '/route/' . $route . '/' . ($params['id'] ?? '');
        });

        $model = new ReviewModel($review, $router);
        $result = $model->toObject();

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertInstanceOf(stdClass::class, $result->review);
        $this->assertSame(42, $result->review->id);
        $this->assertSame('Acme Review', $result->review->name);
        $this->assertSame(10, $result->review->business);
        $this->assertSame('biz@example.com', $result->review->businessEmail);
        $this->assertNull($result->review->fullAddress, 'Full address MUST be null when Place association is null');
        $this->assertSame('/route/api_v1_review_id_remove_post/42', $result->review->urlRemoveReview);
        $this->assertSame('/route/api_v1_review_id_update_post/42', $result->review->urlUpdateReview);
    }

    public function testReviewModelWithValidPlaceReturnsFormattedAddress(): void
    {
        $place = new Place();
        $place->setStreetNumber('777');
        $place->setStreetName('Lucky Blvd');
        $place->setCity('Las Vegas');
        $place->setState('NV');
        $place->setZipCode('89109');
        $place->setCountry('USA');

        $business = $this->createMock(Business::class);
        $business->method('getId')->willReturn(20);
        $business->method('getEmail')->willReturn('casino@example.com');
        $business->method('getPlace')->willReturn($place);

        $review = $this->createMock(Review::class);
        $review->method('getId')->willReturn(99);
        $review->method('getName')->willReturn('Vegas Review');
        $review->method('getLink')->willReturn('https://google.com/review/99');
        $review->method('getBusiness')->willReturn($business);
        $review->method('getQrCodeImgFilename')->willReturn('non_existent_qr.png');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/route/test');

        $model = new ReviewModel($review, $router);
        $result = $model->toObject();

        $this->assertSame('777 Lucky Blvd, Las Vegas, NV 89109, USA', $result->review->fullAddress);
    }

    // =========================================================================
    // Challenge 3: PlaceDetailsService Exception Throw
    // =========================================================================

    public function testPlaceDetailsServiceThrowsInvalidArgumentExceptionOnInvalidComponentType(): void
    {
        $transport = $this->createMock(Transport::class);
        $service   = new PlaceDetailsService($transport, 'dummy_api_key');

        $googleResponse = new GoogleResponse();
        $googleResponse->setData([
            'result' => [
                'address_components' => [
                    [
                        'short_name' => 'Invalid',
                        'types'      => ['unknown_adversarial_type'],
                    ],
                ],
            ],
        ]);

        // Reflect private method _getAddressComponentSection
        $reflectionMethod = new ReflectionMethod(PlaceDetailsService::class, '_getAddressComponentSection');
        $reflectionMethod->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The address component type unknown_adversarial_type is invalid');

        $reflectionMethod->invoke($service, $googleResponse, 'unknown_adversarial_type');
    }

    public function testPlaceDetailsServiceValidTypesDoNotThrow(): void
    {
        $transport = $this->createMock(Transport::class);
        $service   = new PlaceDetailsService($transport, 'dummy_api_key');

        $googleResponse = new GoogleResponse();
        $googleResponse->setData([
            'result' => [
                'address_components' => [
                    [
                        'short_name' => '100',
                        'types'      => [AddressComponentSectionsType::STREET_NUMBER],
                    ],
                    [
                        'short_name' => 'Main St',
                        'types'      => [AddressComponentSectionsType::STREET_NAME],
                    ],
                    [
                        'short_name' => 'Miami',
                        'types'      => [AddressComponentSectionsType::CITY],
                    ],
                    [
                        'short_name' => 'FL',
                        'types'      => [AddressComponentSectionsType::STATE],
                    ],
                    [
                        'short_name' => 'USA',
                        'types'      => [AddressComponentSectionsType::COUNTRY],
                    ],
                    [
                        'short_name' => '33173',
                        'types'      => [AddressComponentSectionsType::ZIP_CODE],
                    ],
                ],
            ],
        ]);

        $reflectionMethod = new ReflectionMethod(PlaceDetailsService::class, '_getAddressComponentSection');
        $reflectionMethod->setAccessible(true);

        foreach (AddressComponentSectionsType::ADDRESS_TYPES as $validType) {
            $value = $reflectionMethod->invoke($service, $googleResponse, $validType);
            $this->assertNotNull($value, "Expected non-null value for valid type: $validType");
            $this->assertIsString($value);
        }
    }

    public function testParseResponseBehaviorWithGoogleAddressComponentContainingNonAddressTypes(): void
    {
        $transport = $this->createMock(Transport::class);
        $service   = new PlaceDetailsService($transport, 'dummy_api_key');

        $googleResponse = new GoogleResponse();
        $googleResponse->setStatus('OK');
        $googleResponse->setData([
            'status' => 'OK',
            'result' => [
                'name'               => 'Googleplex',
                'business_status'    => 'OPERATIONAL',
                'types'              => ['point_of_interest'],
                'address_components' => [
                    [
                        'short_name' => '1600',
                        'types'      => ['street_number'],
                    ],
                    [
                        'short_name' => 'Mountain View',
                        'types'      => ['locality', 'political'],
                    ],
                ],
            ],
        ]);

        $reflectionMethod = new ReflectionMethod(PlaceDetailsService::class, 'parseResponse');
        $reflectionMethod->setAccessible(true);

        // When Google returns 'political', does parseResponse throw InvalidArgumentException?
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The address component type political is invalid');

        $reflectionMethod->invoke($service, $googleResponse);
    }


    // =========================================================================
    // Challenge 4: Offline Redis Failover
    // =========================================================================

    public function testOfflineRedisFailoverOperations(): void
    {
        // Unroutable port triggers immediate Redis offline failure
        $cache = new FallbackRedisCacheRegistry(
            '127.0.0.1',
            'challenger_prefix_',
            null,
            65534,
            $this->projectDir
        );

        $testKey   = 'adversarial_key_' . uniqid();
        $testValue = ['payload' => 'critical_session_data', 'counter' => 12345];

        // 1. Set operation must succeed via FilesystemAdapter fallback
        $setOk = $cache->set($testKey, $testValue, 120);
        $this->assertTrue($setOk, 'set() must return true via fallback adapter');

        // 2. Get operation must retrieve the exact stored value
        $found = false;
        $retrieved = $cache->get($testKey, $found);
        $this->assertTrue($found, 'get() must report found=true');
        $this->assertSame($testValue, $retrieved);

        // 3. Verify fallback cache directory physically exists on disk
        $fallbackDir = $this->projectDir . '/var/cache/app_fallback';
        $this->assertDirectoryExists($fallbackDir, 'Fallback cache directory var/cache/app_fallback must exist on disk');

        // 4. Purge operation must delete the item
        $purgeOk = $cache->purge($testKey);
        $this->assertTrue($purgeOk, 'purge() must return true');

        // 5. Subsequent get must report item not found
        $foundAfterPurge = true;
        $retrievedAfterPurge = $cache->get($testKey, $foundAfterPurge);
        $this->assertFalse($foundAfterPurge, 'Item must be reported not found after purge');
        $this->assertFalse($retrievedAfterPurge);
    }

    public function testOfflineRedisKeySanitizationForbiddenCharacters(): void
    {
        $cache = new FallbackRedisCacheRegistry(
            '127.0.0.1',
            'prefix_',
            null,
            65534,
            $this->projectDir
        );

        // Key containing PSR-6 forbidden characters: {}()/\@:
        $forbiddenKey = 'user:reset@domain.com/path(1){2}';
        $testValue    = 'sanitized_ok';

        $setOk = $cache->set($forbiddenKey, $testValue, 60);
        $this->assertTrue($setOk, 'Keys with PSR-6 forbidden characters must be sanitized without exception');

        $found = false;
        $retrieved = $cache->get($forbiddenKey, $found);
        $this->assertTrue($found);
        $this->assertSame($testValue, $retrieved);

        $cache->purge($forbiddenKey);
    }

    // =========================================================================
    // Challenge 5: ExceptionListener JSON Output in Prod
    // =========================================================================

    public function testExceptionListenerReturnsJsonForApi404InProd(): void
    {
        $router   = $this->createMock(RouterInterface::class);
        $listener = new ExceptionListener($router, 'prod');

        $kernel    = $this->createMock(HttpKernelInterface::class);
        $request   = Request::create('/api/v1/business/999/remove', 'POST');
        $exception = new NotFoundHttpException('Entity business 999 not found');
        $event     = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response, 'Expected JsonResponse for API request in prod');
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));

        $data = json_decode($response->getContent(), true);
        $this->assertSame('EntityNotFoundError', $data['type']);
        $this->assertSame('Entity business 999 not found', $data['message']);
    }

    public function testExceptionListenerReturnsJsonForAbstractApiExceptionInProd(): void
    {
        $router   = $this->createMock(RouterInterface::class);
        $listener = new ExceptionListener($router, 'prod');

        $kernel    = $this->createMock(HttpKernelInterface::class);
        $request   = Request::create('/api/v1/review/generate-guest', 'POST');
        $exception = new ApiNormalOperationException(['Email is invalid', 'Place ID is required']);
        $event     = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('PayloadValidationError', $data['type']);
        $this->assertSame(['Email is invalid', 'Place ID is required'], $data['messages']);
    }

    public function testExceptionListenerReturnsJsonForGeneric500InProd(): void
    {
        $router   = $this->createMock(RouterInterface::class);
        $listener = new ExceptionListener($router, 'prod');

        $kernel    = $this->createMock(HttpKernelInterface::class);
        $request   = Request::create('/api/v1/some-endpoint', 'GET');
        $exception = new RuntimeException('Sensitive database connection credentials leaked');
        $event     = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('UnexpectedFailure', $data['type']);
        $this->assertSame('An unexpected error has occurred.', $data['message']);
        $this->assertStringNotContainsString('credentials', $response->getContent(), 'Must not leak sensitive error details in prod');
    }

    public function testExceptionListenerBypassedInDevEnvironment(): void
    {
        $router   = $this->createMock(RouterInterface::class);
        $listener = new ExceptionListener($router, 'dev'); // dev mode

        $kernel    = $this->createMock(HttpKernelInterface::class);
        $request   = Request::create('/api/v1/business/999/remove', 'POST');
        $exception = new NotFoundHttpException('Not found');
        $event     = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $this->assertNull($event->getResponse(), 'ExceptionListener must NOT set response in non-prod environments');
    }

    public function testExceptionListenerRedirectsHtmlForWebAdminRouteInProd(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(function (string $route) {
            return '/' . $route;
        });

        $listener = new ExceptionListener($router, 'prod');

        $kernel    = $this->createMock(HttpKernelInterface::class);
        $request   = Request::create('/admin/business', 'GET'); // Web route, not API
        $exception = new NotFoundHttpException('Page not found');
        $event     = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response, 'Web routes must receive RedirectResponse');
        $this->assertSame('/admin_404_error', $response->getTargetUrl());
    }

    // =========================================================================
    // Challenge 6: HealthController Status
    // =========================================================================

    public function testHealthCheckDatabaseUp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($connection);

        $controller = new HealthController();
        $response   = $controller->check($doctrine);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('UP', $data['status']);
        $this->assertSame('UP', $data['database']);
        $this->assertArrayHasKey('timestamp', $data);

        // Validate ISO 8601 (ATOM) format
        $parsedDate = DateTime::createFromFormat(DateTime::ATOM, $data['timestamp']);
        $this->assertNotFalse($parsedDate, 'Timestamp must be in valid ISO 8601 / ATOM format');
    }

    public function testHealthCheckDatabaseDown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException(new RuntimeException('Connection timed out'));

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($connection);

        $controller = new HealthController();
        $response   = $controller->check($doctrine);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('DOWN', $data['status']);
        $this->assertSame('DOWN', $data['database']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testHealthCheckDoctrineThrows(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willThrowException(new RuntimeException('No DBAL connections defined'));

        $controller = new HealthController();
        $response   = $controller->check($doctrine);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('DOWN', $data['status']);
        $this->assertSame('DOWN', $data['database']);
    }
}
