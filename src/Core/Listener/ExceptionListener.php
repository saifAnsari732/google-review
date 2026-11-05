<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Core\Listener;

use App\Api\Core\Exception\AbstractApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Handles exceptions
 *
 * @package App\Core\Listener
 */
class ExceptionListener
{
    /**
     * @var RouterInterface
     */
    private $_router;

    /**
     * @var string
     */
    private $_env;

    /**
     * ExceptionListener constructor.
     */
    public function __construct(RouterInterface $router, string $env)
    {
        $this->_router = $router;
        $this->_env    = $env;
    }

    /**
     * @param ExceptionEvent $event
     */
    public function onKernelException(ExceptionEvent $event)
    {
        if ('prod' !== $this->_env) {
            return;
        }

        $exception = $event->getThrowable();
        $request   = $event->getRequest();
        $path      = $request->getPathInfo();

        // Check if request targets an API endpoint or requests JSON
        $isApi = (strpos($path, '/api') === 0)
            || $request->getContentType() === 'json'
            || in_array('application/json', $request->getAcceptableContentTypes(), true);

        if ($isApi) {
            $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
            if ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
            } elseif ($exception instanceof AbstractApiException) {
                $statusCode = Response::HTTP_BAD_REQUEST;
            }

            if ($statusCode === Response::HTTP_NOT_FOUND) {
                $payload = [
                    'type'    => 'EntityNotFoundError',
                    'message' => $exception->getMessage() ?: 'Resource not found',
                ];
            } elseif ($exception instanceof AbstractApiException) {
                $payload = [
                    'type'     => 'PayloadValidationError',
                    'messages' => $exception->getMessageList(),
                ];
            } elseif ($statusCode >= 400 && $statusCode < 500) {
                $payload = [
                    'type'    => 'BadRequest',
                    'message' => $exception->getMessage() ?: 'Bad Request',
                ];
            } else {
                $payload = [
                    'type'    => 'UnexpectedFailure',
                    'message' => 'An unexpected error has occurred.',
                ];
            }

            $response = new JsonResponse($payload, $statusCode);
            $response->headers->set('Content-Type', 'application/json');
            $event->setResponse($response);

            return;
        }

        $isAdmin        = strpos($path, '/admin') === 0;
        $exceptionClass = get_class($exception);

        switch ($exceptionClass) {
            case NotFoundHttpException::class:
                $response = new RedirectResponse($this->_router->generate('landing_404_error'));
                if ($isAdmin) {
                    $response = new RedirectResponse($this->_router->generate('admin_404_error'));
                }
                $event->setResponse($response);
                break;
            default:
                $response = new RedirectResponse($this->_router->generate('landing_500_error'));
                if ($isAdmin) {
                    $response = new RedirectResponse($this->_router->generate('admin_500_error'));
                }
                $event->setResponse($response);
                break;
        }
    }
}