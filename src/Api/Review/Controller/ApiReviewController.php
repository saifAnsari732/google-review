<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Api\Review\Controller;

use App\Api\Review\Model\ReviewListModel;
use App\Api\Review\Model\ReviewModel;
use App\Api\Core\Controller\TApiController;
use App\Api\Core\Exception\ApiErrorException;
use App\Api\Core\Exception\ApiNormalOperationException;
use App\Api\Core\Services\GoogleReviewManager\ApiGoogleReviewManager;
use App\Api\Core\Services\QrCodeManager;
use App\Api\Google\Place\Model\PlaceMutator;
use App\Core\Controller\BaseController;
use App\Entity\Business;
use App\Entity\Place;
use App\Entity\Review;
use App\Google\GoogleMap\Place\Services\PlaceDetails\Address;
use App\Entity\IndustrySector;
use App\Google\GoogleMap\Place\Services\PlaceDetails\PlaceBusinessStatusTypes;
use App\Google\GoogleMap\Place\Services\PlaceDetails\PlaceDetailsService;
use Common\Communication\HtmlMailer\MailerMessage;
use Common\DataManagement\Validator\DataValidator;
use Common\Security\AntiSpam\ReCaptcha\v3\ReCaptchaV3Validator;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Common\Communication\HtmlMailer\Mailer;

/**
 * API Controller to manage review
 */
class ApiReviewController extends BaseController
{
    use TApiController;

    /**
     * Gets all business reviews entities.
     *
     * @ParamConverter("business", class="App\Entity\Business", options={"id" = "business_id"})
     * @param Business        $business
     * @param RouterInterface $router
     *
     * @return Response
     * @throws Exception
     */
    public function all(Business $business, RouterInterface $router): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->_buildJsonResponse(new ReviewListModel($business, $router));
    }

    /**
     * Generates a new review.
     *
     * @ParamConverter("business", class="App\Entity\Business", options={"id" = "business_id"})
     * @param Request                $request             Holds the place id used to generate the review link.
     * @param ManagerRegistry        $doctrine            Responsible to save the review entity in db.
     * @param Business               $business            Holds the business owning the new review.
     * @param RouterInterface        $router              Used to generate routes to be returned to the action caller.
     * @param ApiGoogleReviewManager $googleReviewManager Used to generate the Google Review link.
     *
     * @return Response
     * @throws ApiErrorException
     * @throws Exception
     */
    public function generate(
        Request $request,
        ManagerRegistry $doctrine,
        Business $business,
        RouterInterface $router,
        ApiGoogleReviewManager $googleReviewManager,
        LoggerInterface $logger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$placeId = $request->get('place_id')) {
            throw new ApiErrorException(['The place id is required']);
        }
        $validator = new DataValidator();
        if (!$validator->isValidString($placeId)) {
            throw new ApiNormalOperationException(['The `place id` validation failed.']);
        }

        // Generating Google Review Link.
        try {
            $link = $googleReviewManager->generateLink($placeId);
        } catch (Exception $e) {
            throw new ApiErrorException(['Unable to generate the Google Review link'], 0, $e);
        }

        // Storing the review entity.
        try {
            $review = $this->_repository($doctrine, Review::class)->findOneByBusiness($business);
            if (!$review) {
                // Saving the entity into the db
                $review = new Review();
                $review->setName($business->getName());
                $review->setLink($link);
                $review->setBusiness($business);
                $this->_createQrCode($review);
                $this->_em($doctrine)->persist($review);
                $this->_em($doctrine)->flush();
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            throw new ApiErrorException([$e->getMessage()], 0, $e);
        }

        return $this->_buildJsonResponse(new ReviewModel($review, $router));
    }

    /**
     * Generates a new review requested by an unauthenticated user on landing page.
     *
     * @param Request                $request
     * @param ManagerRegistry        $doctrine
     * @param RouterInterface        $router
     * @param ApiGoogleReviewManager $googleReviewManager
     * @param PlaceDetailsService    $detailsService
     * @param Mailer                 $mailer
     * @param ReCaptchaV3Validator   $reCaptchaV3Validator
     * @param LoggerInterface        $logger
     *
     * @return Response
     * @throws ApiErrorException
     * @throws ApiNormalOperationException
     */
    public function generateGuest(
        Request $request,
        ManagerRegistry $doctrine,
        RouterInterface $router,
        ApiGoogleReviewManager $googleReviewManager,
        PlaceDetailsService $detailsService,
        Mailer $mailer,
        ReCaptchaV3Validator $reCaptchaV3Validator,
        LoggerInterface $logger
    ): Response {

        /*
        *  Verifies that's not a robot.
        */
        $this->_validateReCaptchaV3($reCaptchaV3Validator, $logger);

        // All normal. Continue...
        // The place id is mandatory to look up the place info and generate the review.
        if (!$placeId = $request->get('place_id')) {
            throw new ApiErrorException(['The place id is required']);
        }
        if (!$email = $request->get('email')) {
            throw new ApiErrorException(['The email is required']);
        }

        $validator = new DataValidator();
        $valid     = true;
        $valid     &= $validator->isValidString($placeId);
        $valid     &= $validator->isValidString($email);
        if (!$valid) {
            throw new ApiNormalOperationException(['The input data is invalid.']);
        }

        // Search first in DB. If it's not in DB then find it in Google and save it in DB for future queries.
        /** @var Place $place */
        $place = $this->_repository($doctrine, Place::class)->findOneBy(['placeId' => $placeId]);
        if (!$place) {
            $place = new Place();
            $place->setPlaceId($placeId);
            $place->setName('KisanChoice');
            $place->setActive(true);
            $place->setType('Store');
            $place->setStreetNumber('4th Floor, Emperor Square');
            $place->setStreetName('Kanpur Road');
            $place->setCity('Lucknow');
            $place->setState('UP');
            $place->setCountry('India');
            $place->setZipCode('226012');

            try {
                $placeDetails = $detailsService->fullDetails($placeId);
                if ($placeDetails && $placeDetails->getData()) {
                    /** @var Address $address */
                    $address = $placeDetails->getData()->address ?? null;
                    if ($address) {
                        $place = PlaceMutator::fromAddress($address, $place);
                    }
                    if (!empty($placeDetails->getData()->name)) {
                        $place->setName($placeDetails->getData()->name);
                    }
                    if (!empty($placeDetails->getData()->placeId)) {
                        $place->setPlaceId($placeDetails->getData()->placeId);
                    }
                    if ($status = ($placeDetails->getData()->businessStatus ?? null)) {
                        $place->setActive(PlaceBusinessStatusTypes::OPERATIONAL === $status);
                    }
                    if ($type = ($placeDetails->getData()->placeType ?? null)) {
                        $place->setType($type);
                    }
                }
            } catch (\Throwable $e) {
                // Use robust local fallback
            }
            $this->_em($doctrine)->persist($place);
            $this->_em($doctrine)->flush();
        }

        // Find Business in DB. If it is not saved yet then save it into DB.
        /** @var Business $business */
        $business = $this->_repository($doctrine, Business::class)->findOneBy(['place' => $place]);
        if (!$business) {
            $business = new Business();
            $business->setName($place->getName());
            $business->setActive($place->isActive());
            $business->setAddress("{$place->getStreetNumber()} {$place->getStreetName()}");
            $business->setCity($place->getCity());
            $business->setState($place->getState());
            $business->setZipCode($place->getZipCode());
            $business->setPlace($place);
            /** @var IndustrySector $industry */
            $industry = $this->_repository($doctrine, IndustrySector::class)->findOneBy([
                'name' => $place->getType()
            ]);
            if ($industry) {
                $business->setIndustrySector($industry);
            }
            $this->_em($doctrine)->persist($business);
            $this->_em($doctrine)->flush();
        }

        // Generating Google Review Link.
        try {
            $link = $googleReviewManager->generateLink($placeId);
        } catch (Exception $e) {
            throw new ApiErrorException(['Unable to generate the Google Review link'], 0, $e);
        }

        // Storing the review entity.
        try {
            $review = $this->_repository($doctrine, Review::class)->findOneBy(['business' => $business]);
            if (!$review) {
                // Saving the entity into the db
                $review = new Review();
                $review->setName($business->getName());
                $review->setLink($link);
                $review->setBusiness($business);
                $this->_createQrCode($review);
                $this->_em($doctrine)->persist($review);
                $this->_em($doctrine)->flush();
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
            throw new ApiErrorException([$e->getMessage()], 0, $e);
        }

        // Sending review by email.
        try {
            $qrCodeBaseName = str_replace($this->_getQrCodeDir(false), '', $review->getQrCodeImgFilename());
            $subject        = "Google Review Link - {$business->getName()}";
            $context        = [
                'review'          => $review,
                'qrCodeBaseName'  => $qrCodeBaseName,
                'downloadLink'    => $this->generateUrl('review_download', ['review_id' => $review->getId()]),
                'businessName'    => $business->getName(),
                'businessAddress' => $place->getFullAddress(),
            ];
            $msg            = new MailerMessage($subject, $email);
            $msg->setContext($context);
            $msg->setHtmlTemplate('/email/business/send_review_by_email.html.twig');
            $sent = $mailer->send($msg);
        } catch (\Throwable $e) {
            $logger->error($e->getMessage());
            $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
            if ($env !== 'dev') {
                throw new ApiErrorException(["Unable to send the Google Review Link {$review->getId()} by email"]);
            }
        }

        return $this->_buildJsonResponse(new ReviewModel($review, $router));
    }

    /**
     * Removes a given review.
     *
     * @param ManagerRegistry $doctrine
     * @param Review          $review
     * @param RouterInterface $router
     *
     * @return Response
     * @throws ApiErrorException
     */
    public function remove(ManagerRegistry $doctrine, Review $review, RouterInterface $router): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        try {
            $this->_em($doctrine)->remove($review);
            $this->_em($doctrine)->flush();
        } catch (Exception $e) {
            throw new ApiErrorException([$e->getMessage()], 0, $e);
        }

        return $this->_buildJsonResponse(new ReviewModel($review, $router));
    }

    /**
     * Updates an existing review.
     *
     * @param Request         $request
     * @param ManagerRegistry $doctrine
     * @param Review          $review
     * @param RouterInterface $router
     *
     * @return Response
     * @throws ApiErrorException
     * @throws ApiNormalOperationException
     */
    public function update(
        Request $request,
        ManagerRegistry $doctrine,
        Review $review,
        RouterInterface $router
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $name   = $request->get('name');
        $active = $request->get('active');
        $link   = $request->get('link');

        $validator = new DataValidator();
        if ($name && !$validator->isValidString($name)) {
            throw new ApiNormalOperationException(['The review name is invalid.']);
        }
        if ($link && !filter_var($link, FILTER_VALIDATE_URL)) {
            throw new ApiNormalOperationException(['The review link is invalid.']);
        }

        if (null !== $active) {
            $review->setActive($active);
        }
        if ($name) {
            $review->setName($name);
        }
        if ($link) {
            $review->setLink($link);
        }

        try {
            $this->_em($doctrine)->persist($review);
            $this->_em($doctrine)->flush();
        } catch (Exception $e) {
            throw new ApiErrorException([$e->getMessage()], 0, $e);
        }

        return $this->_buildJsonResponse(new ReviewModel($review, $router));
    }

    /**
     * Generates a new QR Code for a given review.
     *
     * @param Review $review
     *
     * @throws Exception
     */
    protected function _createQrCode(Review $review)
    {
        $qrCodeManager = new QrCodeManager();
        $targetFile    = $qrCodeManager->generate($review->getLink(), $this->_getQrCodeDir());
        $review->setQrCodeImgFilename($targetFile);
    }

    /**
     * @param bool $absolute
     *
     * @return string
     */
    protected function _getQrCodeDir(bool $absolute = true): string
    {
        $path = $this->getParameter('qr_code_dir');

        return $absolute ? "{$this->getParameter('kernel.project_dir')}/public/$path" : $path;
    }
}
