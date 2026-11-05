<?php

namespace App\Tests\Unit\Security;

use App\Controller\Security\User\UserController;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Challenge 4: CSRF Bypass Verification
 *
 * Verifies that user removal in UserController cannot be executed without a valid
 * CSRF token. Tests missing, invalid, mismatched tokens, and self-deletion prevention.
 * Inspects framework.yaml and user_list.html.twig template.
 */
class CsrfProtectionChallengeTest extends TestCase
{
    private Session $session;
    private FlashBag $flashBag;
    private TokenStorage $tokenStorage;
    private RouterInterface $router;

    protected function setUp(): void
    {
        $this->flashBag = new FlashBag();
        $sessionStorage = new MockArraySessionStorage();
        $this->session = new Session($sessionStorage, null, $this->flashBag);

        $this->tokenStorage = new TokenStorage();
        $this->router = $this->createMock(RouterInterface::class);
        $this->router->method('generate')->willReturn('/admin/user/');
    }

    private function createContainerWithServices(
        CsrfTokenManagerInterface $csrfTokenManager,
        ?User $authenticatedUser = null
    ): Container {
        $container = new Container();

        if ($authenticatedUser) {
            $token = new UsernamePasswordToken($authenticatedUser, 'main', $authenticatedUser->getRoles());
            $this->tokenStorage->setToken($token);
        }

        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $requestStack->push($request);

        $container->set('request_stack', $requestStack);
        $container->set('session', $this->session);
        $container->set('router', $this->router);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('security.token_storage', $this->tokenStorage);

        return $container;
    }

    /**
     * Attack 1: Request with MISSING CSRF token
     */
    public function testMissingCsrfTokenRejectsDeletion(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');

        $targetUser = new User();
        $targetUser->setEmail('target@example.com');
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($targetUser, 42);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(false);

        $controller = new UserController();
        $controller->setContainer($this->createContainerWithServices($csrfManager, $admin));

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        // Missing _token in POST/GET
        $request = new Request([], []);

        $response = $controller->remove($request, $doctrine, $targetUser);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains('Invalid security token.', $this->flashBag->get('error'));
    }

    /**
     * Attack 2: Request with INVALID / FORGED CSRF token
     */
    public function testInvalidCsrfTokenRejectsDeletion(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');

        $targetUser = new User();
        $targetUser->setEmail('target@example.com');
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($targetUser, 42);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->expects($this->once())
            ->method('isTokenValid')
            ->with($this->callback(function (CsrfToken $token) {
                return $token->getId() === 'delete_user_42' && $token->getValue() === 'forged_csrf_token_123';
            }))
            ->willReturn(false);

        $controller = new UserController();
        $controller->setContainer($this->createContainerWithServices($csrfManager, $admin));

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        $request = new Request([], ['_token' => 'forged_csrf_token_123']);

        $response = $controller->remove($request, $doctrine, $targetUser);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains('Invalid security token.', $this->flashBag->get('error'));
    }

    /**
     * Attack 3: Request with MISMATCHED CSRF token (token for user 99 used on user 42)
     */
    public function testMismatchedCsrfTokenRejectsDeletion(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');

        $targetUser = new User();
        $targetUser->setEmail('target@example.com');
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($targetUser, 42);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        // Only valid for delete_user_99
        $csrfManager->method('isTokenValid')->willReturnCallback(function (CsrfToken $token) {
            return $token->getId() === 'delete_user_99';
        });

        $controller = new UserController();
        $controller->setContainer($this->createContainerWithServices($csrfManager, $admin));

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        $request = new Request([], ['_token' => 'token_for_user_99']);

        $response = $controller->remove($request, $doctrine, $targetUser);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains('Invalid security token.', $this->flashBag->get('error'));
    }

    /**
     * Attack 4: Self-deletion attempt by authenticated user
     */
    public function testSelfDeletionIsBlockedEvenWithValidCsrfToken(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($admin, 1);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true); // Token is valid!

        $controller = new UserController();
        $controller->setContainer($this->createContainerWithServices($csrfManager, $admin));

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        $request = new Request([], ['_token' => 'valid_token_for_admin_1']);

        // Admin attempts to delete admin
        $response = $controller->remove($request, $doctrine, $admin);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains('The authenticated user cannot be removed.', $this->flashBag->get('error'));
    }

    /**
     * Legitimate Case: Valid CSRF token by authorized admin for another user
     */
    public function testValidCsrfTokenAllowsDeletion(): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');

        $targetUser = new User();
        $targetUser->setEmail('target@example.com');
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($targetUser, 42);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);

        $controller = new UserController();
        $controller->setContainer($this->createContainerWithServices($csrfManager, $admin));

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->once())->method('remove')->with($targetUser);
        $em->expects($this->once())->method('flush');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        $request = new Request([], ['_token' => 'legit_token_42']);

        $response = $controller->remove($request, $doctrine, $targetUser);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains('The user "target@example.com" has been removed successfully.', $this->flashBag->get('success'));
    }

    /**
     * Framework Configuration Verification: framework.yaml must enable csrf_protection
     */
    public function testFrameworkConfigurationEnablesCsrf(): void
    {
        $frameworkConfig = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/framework.yaml');
        $csrfProtection = $frameworkConfig['framework']['csrf_protection'] ?? null;

        $this->assertTrue(
            $csrfProtection === true || (is_array($csrfProtection) && ($csrfProtection['enabled'] ?? true) === true),
            'framework.csrf_protection must be enabled in config/packages/framework.yaml'
        );
    }

    /**
     * Template Inspection: user_list.html.twig must render a POST form with CSRF token
     */
    public function testTemplateRendersPostFormWithCsrfToken(): void
    {
        $templateContent = file_get_contents(
            dirname(__DIR__, 3) . '/templates/security/user/user_list.html.twig'
        );

        $this->assertStringContainsString('method="post"', $templateContent, 'User delete action must use POST method');
        $this->assertStringContainsString('name="_token"', $templateContent, 'User delete form must include _token input');
        $this->assertStringContainsString("csrf_token('delete_user_'", $templateContent, 'CSRF token identifier must be delete_user_');
    }
}
