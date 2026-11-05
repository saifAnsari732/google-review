<?php

namespace App\Tests\Unit\Security;

use App\Api\Security\ChangePassword\Controller\ChangePasswordController;
use App\Entity\User;
use App\Repository\UserRepository;
use Common\Communication\HtmlMailer\Mailer;
use Common\Communication\HtmlMailer\MailerMessage;
use Common\DataStorage\Redis\RedisCacheRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Challenge 2: Host Header Tampering & Origin Poisoning Verification
 *
 * Verifies that ChangePasswordController::sendLinkToUpdatePassword() generates
 * password reset URLs immune to Host Header / HTTP_ORIGIN tampering attacks.
 */
class HostHeaderPoisoningChallengeTest extends TestCase
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
     * Adversarial Attack: Attacker sends malicious HTTP_ORIGIN and Origin headers
     * attempting to poison the reset URL sent to the victim.
     */
    public function testOriginHeaderCannotPoisonResetUrl(): void
    {
        $controller = new ChangePasswordController();
        $controller->setContainer($this->createContainerWithParams());

        // Set up attacker-controlled Origin environment
        $attackerOrigin = 'https://attacker-controlled-phish.evil.com';
        $_SERVER['HTTP_ORIGIN'] = $attackerOrigin;

        // Configure request with malicious headers
        $request = new Request([], ['email' => 'victim@company.com']);
        $request->headers->set('Origin', $attackerOrigin);
        $request->headers->set('Referer', "$attackerOrigin/login");
        $request->headers->set('X-Forwarded-Host', 'attacker-controlled-phish.evil.com');

        // Mock legitimate user repository with findOneByEmail
        $user = new User();
        $user->setEmail('victim@company.com');

        $repository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['findOneByEmail'])
            ->getMock();
        $repository->method('findOneByEmail')->with('victim@company.com')->willReturn($user);

        $em = $this->createMock(ObjectManager::class);
        $em->method('getRepository')->with(User::class)->willReturn($repository);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);

        // Real router with secure RequestContext bound to app domain
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with(
                'change_password',
                $this->callback(function ($params) {
                    return isset($params['hash']) && strlen($params['hash']) === 64;
                }),
                RouterInterface::ABSOLUTE_URL
            )
            ->willReturnCallback(function ($route, $params) {
                // Legitimate router generates URL based on its own context
                return "https://reviews.legitbusiness.com/change-password/{$params['hash']}";
            });

        // Mock cache and capture stored data
        $cachedData = null;
        $cache = $this->createMock(RedisCacheRegistry::class);
        $cache->expects($this->once())
            ->method('set')
            ->willReturnCallback(function ($hash, $data, $ttl) use (&$cachedData) {
                $cachedData = $data;
                return true;
            });

        // Mock mailer and capture outgoing message
        /** @var MailerMessage|null $capturedMessage */
        $capturedMessage = null;
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (MailerMessage $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;
                return true;
            });

        // Execute controller
        $response = $controller->sendLinkToUpdatePassword($request, $mailer, $cache, $router, $doctrine);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($cachedData, 'Reset token data must be cached');
        $this->assertNotNull($capturedMessage, 'Password reset email must be sent');

        // VERIFY: Cached link must NOT contain attacker's origin
        $this->assertStringNotContainsString(
            'attacker-controlled-phish.evil.com',
            $cachedData->link,
            'Cached reset link was poisoned with attacker Origin!'
        );
        $this->assertStringStartsWith('https://reviews.legitbusiness.com/change-password/', $cachedData->link);

        // VERIFY: Outgoing email context link must NOT contain attacker's origin
        $context = $capturedMessage->getContext();
        $this->assertStringNotContainsString(
            'attacker-controlled-phish.evil.com',
            $context['link'],
            'Email context link was poisoned with attacker Origin!'
        );
        $this->assertStringStartsWith('https://reviews.legitbusiness.com/change-password/', $context['link']);

        // Clean up global environment
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * Source Code Verification: Ensure $_SERVER['HTTP_ORIGIN'] is not accessed in executable code
     * in ChangePasswordController.php
     */
    public function testSourceCodeDoesNotContainServerHttpOrigin(): void
    {
        $code = file_get_contents(
            dirname(__DIR__, 3) . '/src/Api/Security/ChangePassword/Controller/ChangePasswordController.php'
        );

        // Strip comments to test executable tokens
        $tokens = token_get_all($code);
        $executableCode = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $executableCode .= $token[1];
                }
            } else {
                $executableCode .= $token;
            }
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\$_SERVER\s*\[\s*[\'"]HTTP_ORIGIN[\'"]\s*\]/',
            $executableCode,
            '$_SERVER["HTTP_ORIGIN"] must not be accessed in controller executable code'
        );
        $this->assertStringNotContainsString(
            '$_SERVER',
            $executableCode,
            '$_SERVER must not be directly accessed in controller executable code'
        );
    }
}
