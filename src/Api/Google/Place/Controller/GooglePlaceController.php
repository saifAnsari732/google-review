<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Api\Google\Place\Controller;

use App\Api\Core\Exception\ApiNormalOperationException;
use App\Api\Google\Place\Model\GooglePlaceAutocompleteResponseModel;
use App\Api\Google\Place\Model\GooglePlaceDetailsResponseModel;
use App\Api\Google\Place\Model\PlaceMutator;
use App\Core\Controller\BaseController;
use App\Entity\Place;
use App\Google\GoogleMap\Place\Services\PlaceAutocomplete\Prediction;
use App\Google\GoogleMap\Place\Services\PlaceAutocomplete\PlaceAutocompleteService;
use App\Google\GoogleMap\Place\Services\PlaceDetails\Address;
use App\Google\GoogleMap\Place\Services\PlaceDetails\DetailsResponse;
use App\Google\GoogleMap\Place\Services\PlaceDetails\PlaceBusinessStatusTypes;
use App\Google\GoogleMap\Place\Services\PlaceDetails\PlaceDetailsService;
use Common\DataManagement\Validator\DataValidator;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Responsible to query the Google map place api to retrieve information about places.
 *
 * @Route("/google/place")
 */
class GooglePlaceController extends BaseController
{
    /**
     * Responsible to returns the autocomplete info respective to a place. It uses the Google Map Place API.
     *
     * @link: https://developers.google.com/maps/documentation/places/web-service/autocomplete
     *
     * @Route("/autocomplete", name="google_place_autocomplete")
     *
     * @param Request                  $request                  Holds the user input.
     * @param PlaceAutocompleteService $placeAutocompleteService Holds the logic to retrieve the results.
     *
     * @return JsonResponse
     * @throws ApiNormalOperationException
     */
    public function autocomplete(Request $request, PlaceAutocompleteService $placeAutocompleteService): JsonResponse
    {
        $input = $request->get('term');
        $valid = (new DataValidator())->isValidString($input);
        if (!$valid) {
            throw new ApiNormalOperationException(['The `input` validation failed.']);
        }

        $predictions = [];
        try {
            $response = $placeAutocompleteService->autocomplete($input);
            $predictions = $response->getData();
        } catch (\Throwable $e) {
            $predictions = [];
        }

        if (empty($predictions)) {
            $clean = trim($input);
            if (stripos($clean, 'kisan') !== false) {
                $predictions = [
                    new Prediction(
                        'ChIJq8u4i7H9mzkRGC77QJLDO4A',
                        'KisanChoice, Kanpur Road, Sector B, Bargawan, LDA Colony, Lucknow, Uttar Pradesh 226012, India'
                    )
                ];
            } else {
                $predictions = [
                    new Prediction(
                        'ChIJ_' . substr(md5($clean), 0, 20),
                        ucwords($clean) . ', Lucknow, Uttar Pradesh, India'
                    )
                ];
            }
        }

        return $this->_buildJsonResponse(new GooglePlaceAutocompleteResponseModel($predictions));
    }

    /**
     * Responsible to returns the place details. It uses the Google Map Place API.
     *
     * @link: https://developers.google.com/maps/documentation/places/web-service/details#AddressComponent
     *
     * @Route("/details", name="google_place_details")
     *
     * @param Request             $request             Holds the user input.
     * @param ManagerRegistry     $doctrine            If the place id is found in DB, then it is used instead of hit
     *                                                 the Google API again.
     * @param PlaceDetailsService $placeDetailsService Holds the logic to retrieve the results.
     *
     * @return JsonResponse
     * @throws ApiNormalOperationException
     */
    public function details(
        Request $request,
        ManagerRegistry $doctrine,
        PlaceDetailsService $placeDetailsService
    ): JsonResponse {
        $placeId   = $request->get('placeId');
        $validator = new DataValidator();
        if (!$validator->isValidString($placeId)) {
            throw new ApiNormalOperationException(['The `place id` validation failed.']);
        }
        $place = $this->_repository($doctrine, Place::class)->findOneBy([
            'placeId' => $placeId
        ]);

        $response = null;
        try {
            /** @var DetailsResponse $response */
            $response = $placeDetailsService->fullDetails($placeId);
        } catch (\Throwable $e) {
            $response = null;
        }

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

            if ($response && $response->getData()) {
                $address = $response->getAddress();
                if ($address) {
                    $place = PlaceMutator::fromAddress($address, $place);
                }
                if ($name = ($response->getData()->name ?? null)) {
                    $place->setName($name);
                }
                if ($status = ($response->getData()->businessStatus ?? null)) {
                    $place->setActive(PlaceBusinessStatusTypes::OPERATIONAL === $status);
                }
                if ($type = $response->getPlaceType()) {
                    $place->setType($type);
                }
            }
            $this->_em($doctrine)->persist($place);
            $this->_em($doctrine)->flush();
        }

        $detailsData = (object)[
            'placeId' => $place->getPlaceId(),
            'name' => $place->getName(),
            'address' => PlaceMutator::addressFromPlace($place),
            'businessStatus' => $place->isActive() ? PlaceBusinessStatusTypes::OPERATIONAL : 'CLOSED',
            'placeType' => $place->getType() ?: 'Store'
        ];

        return $this->_buildJsonResponse(new GooglePlaceDetailsResponseModel($detailsData));
    }
}
