<?php

namespace App\Tests\Unit\Security;

use App\Api\Business\Controller\ApiBusinessController;
use App\Api\Review\Controller\ApiReviewController;
use App\Entity\Business;
use App\Entity\Review;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;

/**
 * Challenge 1: BOLA/IDOR Security Verification
 *
 * Verifies that all administrative business and review endpoints cannot be executed
 * by unauthenticated callers or unauthorized users (lacking ROLE_ADMIN).
 * Validates both controller-level guards and firewall access_control configuration.
 */
class BOLAAndIDORChallengeTest extends TestCase
{
    private function createContainerWithRoles(array $grantedRoles): Container
    {
        $container = new Container();
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(
            function ($attribute) use ($grantedRoles) {
                return in_array($attribute, $grantedRoles, true);
            }
        );
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to delete a business via ApiBusinessController::remove()
     */
    public function testUnauthenticatedCannotRemoveBusiness(): void
    {
        $controller = new ApiBusinessController();
        $controller->setContainer($this->createContainerWithRoles([])); // Anonymous / unauthenticated

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager'); // DB MUST NOT BE ACCESSED

        $business = new Business();
        $business->setName('Targeted Corporation');

        $this->expectException(AccessDeniedException::class);
        $controller->remove($doctrine, $business);
    }

    /**
     * Adversarial Attack: Standard non-admin user (ROLE_USER) attempts to delete a business
     */
    public function testRoleUserCannotRemoveBusiness(): void
    {
        $controller = new ApiBusinessController();
        $controller->setContainer($this->createContainerWithRoles(['ROLE_USER']));

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $business = new Business();
        $business->setName('Targeted Corporation');

        $this->expectException(AccessDeniedException::class);
        $controller->remove($doctrine, $business);
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to send business reviews by email
     */
    public function testUnauthenticatedCannotSendReviewsByEmail(): void
    {
        $controller = new ApiBusinessController();
        $controller->setContainer($this->createContainerWithRoles([]));

        $mailer = $this->createMock(\Common\Communication\HtmlMailer\Mailer::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $request = new Request();
        $business = new Business();

        $this->expectException(AccessDeniedException::class);
        $controller->sendByEmail($request, $mailer, $doctrine, $business);
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to fetch all reviews via ApiReviewController::all()
     */
    public function testUnauthenticatedCannotListAllReviews(): void
    {
        $controller = new ApiReviewController();
        $controller->setContainer($this->createContainerWithRoles([]));

        $business = new Business();
        $router = $this->createMock(RouterInterface::class);

        $this->expectException(AccessDeniedException::class);
        $controller->all($business, $router);
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to generate admin review link
     */
    public function testUnauthenticatedCannotGenerateReview(): void
    {
        $controller = new ApiReviewController();
        $controller->setContainer($this->createContainerWithRoles([]));

        $request = new Request();
        $doctrine = $this->createMock(ManagerRegistry::class);
        $business = new Business();
        $router = $this->createMock(RouterInterface::class);
        $googleReviewManager = $this->createMock(\App\Api\Core\Services\GoogleReviewManager\ApiGoogleReviewManager::class);
        $logger = new NullLogger();

        $this->expectException(AccessDeniedException::class);
        $controller->generate($request, $doctrine, $business, $router, $googleReviewManager, $logger);
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to delete review via ApiReviewController::remove()
     */
    public function testUnauthenticatedCannotRemoveReview(): void
    {
        $controller = new ApiReviewController();
        $controller->setContainer($this->createContainerWithRoles([]));

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $review = new Review();
        $router = $this->createMock(RouterInterface::class);

        $this->expectException(AccessDeniedException::class);
        $controller->remove($doctrine, $review, $router);
    }

    /**
     * Adversarial Attack: Unauthenticated attempt to update review link
     */
    public function testUnauthenticatedCannotUpdateReview(): void
    {
        $controller = new ApiReviewController();
        $controller->setContainer($this->createContainerWithRoles([]));

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects($this->never())->method('getManager');

        $request = new Request([], ['link' => 'http://malicious-phishing-url.com']);
        $review = new Review();
        $router = $this->createMock(RouterInterface::class);

        $this->expectException(AccessDeniedException::class);
        $controller->update($request, $doctrine, $review, $router);
    }

    /**
     * Firewall Access Control Verification:
     * Inspects security.yaml to ensure all API endpoints default to ROLE_ADMIN
     * and only explicitly designated public endpoints are exempt.
     */
    public function testSecurityAccessControlRulesEnforceRoleAdmin(): void
    {
        $securityConfig = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/security.yaml');
        $accessControl = $securityConfig['security']['access_control'] ?? [];

        $this->assertNotEmpty($accessControl, 'security.access_control must not be empty');

        $hasApiRoleAdminCatchAll = false;
        $hasApiAdminRoleAdmin = false;
        $whitelistedPaths = [];

        foreach ($accessControl as $rule) {
            $path = $rule['path'];
            $roles = (array)($rule['roles'] ?? []);

            if ($path === '^/api/v1/' && in_array('ROLE_ADMIN', $roles, true)) {
                $hasApiRoleAdminCatchAll = true;
            }
            if ($path === '^/api/v1/admin/' && in_array('ROLE_ADMIN', $roles, true)) {
                $hasApiAdminRoleAdmin = true;
            }
            if (in_array('IS_AUTHENTICATED_ANONYMOUSLY', $roles, true) || in_array('PUBLIC_ACCESS', $roles, true)) {
                $whitelistedPaths[] = $path;
            }
        }

        $this->assertTrue($hasApiAdminRoleAdmin, 'Explicit rule ^/api/v1/admin/ must enforce ROLE_ADMIN');
        $this->assertTrue($hasApiRoleAdminCatchAll, 'Catch-all rule ^/api/v1/ must enforce ROLE_ADMIN');

        // Verify that dangerous administrative routes are NOT in whitelisted paths
        $dangerousPaths = [
            '^/api/v1/admin/business/',
            '^/api/v1/review/',
            '^/api/v1/business/',
            '^/admin/',
        ];
        foreach ($whitelistedPaths as $whitelisted) {
            foreach ($dangerousPaths as $dangerous) {
                if ($whitelisted === '^/api/v1/review/generate-guest') {
                    continue; // Allowed guest endpoint
                }
                $this->assertFalse(
                    strpos($whitelisted, $dangerous) === 0,
                    "Administrative path $dangerous must never be whitelisted: matched $whitelisted"
                );
            }
        }
    }
}
