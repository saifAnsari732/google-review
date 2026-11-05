<?php

namespace App\Tests\Unit\Security;

use App\Api\Core\Exception\ApiNormalOperationException;
use App\Api\Security\ChangePassword\Controller\ChangePasswordController;
use App\Entity\User;
use App\Repository\UserRepository;
use Common\Communication\HtmlMailer\Mailer;
use Common\DataStorage\Redis\RedisCacheRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Challenge 6: Anti-Enumeration & Null Pointer Defense Verification
 *
 * Verifies that ChangePasswordController:
 * 1. Safely handles non-existent emails without fatal null pointer exceptions (CWE-476).
 * 2. Emits an identical uniform ApiEmptyResponse ({}) for existing vs non-existing emails,
 *    preventing user enumeration information disclosure (CWE-200).
 * 3. Strictly validates email format against malformed, blank, and injection payloads.
 */
class AntiEnumerationChallengeTest extends TestCase
{
    private function createContainerWithParams(): Container
    {
        $container = new Container();
        $parameterBag = new ParameterBag([
            'system_title' => 'Google Review Manager Test',
            'kernel.project_dir' => dirname(__DIR__, 3),
        ]);
        $container->set('parameter_bag', $parameterBag);

        return $container;
    }

    /**
     * Non-Existent User Test: Verify uniform HTTP 200 ApiEmptyResponse ({}), no 500 error,
     * and that mailer/cache are never invoked.
     */
    public function testNonExistentUserReturnsUniformSuccessWithoutFatalError(): void
    {
        $controller = new ChangePasswordController();
        $controller->setContainer($this->createContainerWithParams());

        $request = new Request([], ['email' => 'attacker_probe_does_not_exist@example.com']);

        // Repository returns null (user not found)
        $repository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneByEmail'])
            ->getMock();
        $repository->expects($this->once())
            ->method('findOneByEmail')
            ->with('attacker_probe_does_not_exist@example.com')
            ->willReturn(null);

        $em = $this->createMock(ObjectManager::class);
        $em->method('getRepository')->with(User::class)->willReturn($repository);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        // Mailer, Cache, and Router must NOT be called for non-existent user
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->never())->method('send');

        $cache = $this->createMock(RedisCacheRegistry::class);
        $cache->expects($this->never())->method('set');

        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->never())->method('generate');

        $response = $controller->sendLinkToUpdatePassword($request, $mailer, $cache, $router, $doctrine);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('{}', $response->getContent());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame([], $payload);
    }

    /**
     * Uniformity Verification: Compare responses between existent and non-existent users.
     * Both must yield identical HTTP status and payload ({}) to prevent user enumeration.
     */
    public function testResponsesAreIdenticalForExistentAndNonExistentUsers(): void
    {
        $controller = new ChangePasswordController();
        $controller->setContainer($this->createContainerWithParams());

        // 1. Existing user run
        $existingUser = new User();
        $existingUser->setEmail('existing_user@example.com');

        $repoExisting = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneByEmail'])
            ->getMock();
        $repoExisting->method('findOneByEmail')->with('existing_user@example.com')->willReturn($existingUser);

        $emExisting = $this->createMock(ObjectManager::class);
        $emExisting->method('getRepository')->willReturn($repoExisting);

        $doctrineExisting = $this->createMock(ManagerRegistry::class);
        $doctrineExisting->method('getManager')->willReturn($emExisting);

        $mailer = $this->createMock(Mailer::class);
        $mailer->method('send')->willReturn(true);

        $cache = $this->createMock(RedisCacheRegistry::class);
        $cache->method('set')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/change-password/token123');

        $reqExisting = new Request([], ['email' => 'existing_user@example.com']);
        $respExisting = $controller->sendLinkToUpdatePassword(
            $reqExisting,
            $mailer,
            $cache,
            $router,
            $doctrineExisting
        );

        // 2. Non-existing user run
        $repoNonExisting = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneByEmail'])
            ->getMock();
        $repoNonExisting->method('findOneByEmail')->with('ghost_user@example.com')->willReturn(null);

        $emNonExisting = $this->createMock(ObjectManager::class);
        $emNonExisting->method('getRepository')->willReturn($repoNonExisting);

        $doctrineNonExisting = $this->createMock(ManagerRegistry::class);
        $doctrineNonExisting->method('getManager')->willReturn($emNonExisting);

        $reqNonExisting = new Request([], ['email' => 'ghost_user@example.com']);
        $respNonExisting = $controller->sendLinkToUpdatePassword(
            $reqNonExisting,
            $mailer,
            $cache,
            $router,
            $doctrineNonExisting
        );

        // Assert identical responses
        $this->assertSame($respExisting->getStatusCode(), $respNonExisting->getStatusCode());
        $this->assertSame(
            $respExisting->getContent(),
            $respNonExisting->getContent(),
            'Response payload differs between existent and non-existent users (User Enumeration vulnerability!)'
        );
        $this->assertSame('{}', $respExisting->getContent());
        $this->assertSame('{}', $respNonExisting->getContent());
    }

    /**
     * Input Validation Test: Malformed, blank, and adversarial email payloads must be rejected
     * with ApiNormalOperationException containing 'The email is invalid.'.
     *
     * @dataProvider invalidEmailProvider
     */
    public function testMalformedEmailInputsAreRejected(string $invalidEmail): void
    {
        $controller = new ChangePasswordController();
        $controller->setContainer($this->createContainerWithParams());

        $request = new Request([], ['email' => $invalidEmail]);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $mailer = $this->createMock(Mailer::class);
        $cache = $this->createMock(RedisCacheRegistry::class);
        $router = $this->createMock(RouterInterface::class);

        try {
            $controller->sendLinkToUpdatePassword($request, $mailer, $cache, $router, $doctrine);
            $this->fail('Expected ApiNormalOperationException was not thrown for invalid email: ' . $invalidEmail);
        } catch (ApiNormalOperationException $e) {
            $this->assertContains('The email is invalid.', $e->getMessageList());
        }
    }

    public function invalidEmailProvider(): array
    {
        return [
            'empty string' => [''],
            'not an email' => ['not-an-email'],
            'missing username' => ['@domain.com'],
            'missing domain' => ['user@'],
            'sql injection payload' => ["' OR '1'='1"],
            'xss payload' => ['<script>alert(1)</script>@domain.com'],
            'spaces in email' => ['user @domain.com'],
            'double at sign' => ['user@@domain.com'],
        ];
    }
}
