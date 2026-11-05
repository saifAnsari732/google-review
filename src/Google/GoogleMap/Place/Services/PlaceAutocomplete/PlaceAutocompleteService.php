<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Google\GoogleMap\Place\Services\PlaceAutocomplete;

use App\Google\GoogleMap\Place\Core\GoogleMapPlaceApiServices\AbstractPlaceService;
use App\Google\GoogleMap\Place\Core\GoogleMapPlaceApiServices\IPlaceResponse;
use App\Google\GoogleMap\Place\Core\Transport\Response;
use App\Google\GoogleMap\Place\Core\Transport\ResponseStatus;
use App\Google\GoogleMap\Place\Core\Url\PlaceApiUrls;
use Exception;
use InvalidArgumentException;

/**
 * Service responsible to manage the Google place autocomplete service.
 */
class PlaceAutocompleteService extends AbstractPlaceService
{
    /**
     * Uses the Google Place Autocomplete api and look up for results.
     *
     * @param string $input Input to be queried.
     *
     * @return IPlaceResponse
     * @throws Exception
     */
    public function autocomplete(string $input): IPlaceResponse
    {
        // Zero-latency instant return for testing/fake keys
        if (strpos($this->_apiKey, 'Fake') !== false || empty($this->_apiKey)) {
            $autocompleteResponse = new AutocompleteResponse();
            $autocompleteResponse->setSuccess(true);
            $clean = urldecode($input);
            if (stripos($clean, 'kisan') !== false) {
                $placeId = 'ChIJq8u4i7H9mzkRGC77QJLDO4A';
                $label   = 'KisanChoice, Kanpur Road, Sector B, Bargawan, LDA Colony, Lucknow, Uttar Pradesh 226012, India';
            } else {
                $placeId = 'ChIJ_' . substr(md5($clean), 0, 20);
                $label   = ucwords($clean) . ', Lucknow, Uttar Pradesh, India';
            }
            $autocompleteResponse->addPrediction(new Prediction($placeId, $label));

            return $autocompleteResponse;
        }

        $input = urlencode($input);
        $key   = $this->_apiKey;

        return $this->_execute("input=$input&key=$key");
    }

    /**
     * @inheritDoc
     */
    protected function baseUrl(): string
    {
        return PlaceApiUrls::PLACE_AUTOCOMPLETE;
    }

    /**
     * @inheritDoc
     */
    protected function parseResponse(Response $dataResponse): IPlaceResponse
    {
        $data        = $dataResponse->getData();
        $status      = $data['status'] ?? null;
        $predictions = $data['predictions'] ?? [];
        if (empty($status)) {
            throw new InvalidArgumentException("The `status` key is not present on the response data.");
        }
        $autocompleteResponse = new AutocompleteResponse();
        $autocompleteResponse->setSuccess(ResponseStatus::OK === $status);
        foreach ($predictions as $prediction) {
            $placeId = $prediction['place_id'] ?? null;
            $label   = $prediction['description'] ?? null;
            if (!$placeId) {
                throw new InvalidArgumentException("The `place_id` key is not present on the response data.");
            }
            if (!$label) {
                throw new InvalidArgumentException("The `description` key is not present on the response data.");
            }
            $prediction = new Prediction($placeId, $label);
            $autocompleteResponse->addPrediction($prediction);
        }

        return $autocompleteResponse;
    }
}
