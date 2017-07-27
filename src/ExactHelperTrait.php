<?php

namespace BohSchu\Exact;

use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

trait ExactHelperTrait
{
    /**
     * Fetch account guid by code
     *
     * @param $account
     * @return String
     */
    protected function getAccountId($account)
    {
        $id = strlen($account['id']) == 5 ? '10' . (string) $account['id'] : $account['id'];

        $uri = '/api/v1/'
            . $this->division .'/crm/Accounts?$filter=startswith(trim(Code),' . "'" . $id . "') "
            . 'eq true&$select=ID';

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch contact guid by code
     *
     * @param $contact
     * @param $accountId
     * @return String
     */
    protected function getContactId($contact, $accountId)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Contacts?$filter=Account eq guid' . "'" . $accountId . "'"
            . ' and LastName eq ' . "'" . $contact->last_name . "'"
            . ' and FirstName eq ' . "'" . $contact->first_name . "'" . '&$select=ID';

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch address guid by account id, street and postcode
     *
     * @param $address
     * @param $accountId
     * @return String
     */
    protected function getAddressId($address, $accountId)
    {
        $name = str_replace('&', '%26', $address->delivery_name);

        $uri = '/api/v1/'. $this->division
            .'/crm/Addresses?$filter=Account eq guid' . "'" . $accountId . "'"
            .' and startswith(trim(AddressLine1),'
            . "'" . $address->delivery_street . "') " . 'eq true'
            . ' and Type eq 4 and AddressLine3 eq ' . "'" . $name . "'"
            .' and Postcode eq '. "'" . $address->delivery_zip_code . "'" . '&$select=ID';

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch product guid for salesOrderLines
     *
     * @param  $products
     * @param  $countryCode
     * @param  $deliveryCountryCode
     * @param  $ustid
     * @param  $deliveryDate
     * @return Array
     */
    protected function getItemIds($products, $countryCode, $deliveryCountryCode, $ustid, $deliveryDate = true)
    {
        $return = [];

        foreach ($products as $key => $value) {
            $uri = '/api/v1/'. $this->division .'/logistics/Items?$filter=trim(Code) eq ' . "'" . str_replace('+', '%2B', $value->variant->sku) . "'" . '&$select=ID';

            $itemId = Cache::remember('exact.item.' . $value->variant->sku, 43200, function () use ($uri) {
                return $this->get($uri)->d->results;
            });

            if(isset($itemId[0]->ID)) {
                $return[$key]['Item'] = $itemId[0]->ID;
                $return[$key]['Quantity'] = $value->amount;
                $return[$key]['Notes'] = $value->individualized;

                if (isset($value->price)) {
                    $return[$key]['NetPrice'] = $value->price;
                }

                if ($deliveryDate) {
                    $return[$key]['DeliveryDate'] = Carbon::today()->addWeekDays($value->variant->deliveryDays)->format('Y-m-d');
                }
            }

            if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
                $return[$key]['VATCode'] = 3;
            } else if($countryCode == 'CH' && $deliveryCountryCode == 'CH') {
                $return[$key]['VATCode'] = '00';
            } else if($countryCode != 'DE' && $deliveryCountryCode != 'DE' && $ustid == '') {
                $return[$key]['VATCode'] = 3;
            }
        }

        return $return;
    }

    /**
     * Fetch the right delivery costs
     *
     * @param $cost
     * @param $countryCode
     * @param $deliveryCountryCode
     * @param $ustId
     * @return Array
     */
    protected function getDeliveryCosts($cost, $countryCode, $deliveryCountryCode, $ustId)
    {
        $uri = '/api/v1/'. $this->division
            .'/logistics/Items?$filter=trim(Code) eq '
            . "'Versand " . $deliveryCountryCode . "'" . '&$select=ID';

        $itemId = Cache::remember('exact.delivery.' . $deliveryCountryCode, 129600, function () use ($uri) {
            return $this->get($uri)->d->results[0]->ID;
        });

        $return = [
            'Item' => $itemId,
            'Quantity' => 1,
            'NetPrice' => (float) $cost
        ];

        if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
            $return['VATCode'] = 3;
        } else if($countryCode == 'CH' && $deliveryCountryCode == 'CH') {
            $return['VATCode'] = '00';
        } else if($countryCode != 'DE' && $deliveryCountryCode != 'DE' && $ustId == '') {
            $return['VATCode'] = 3;
        }

        return $return;
    }

    /**
     * Fetch the right accounting codes by country code
     *
     * @param $countryCode
     * @param $ustId
     * @return Array
     */
    protected function getAccountingCodes($countryCode, $ustId)
    {
        if ($countryCode == 'DE') {
            $accounting['vatCode'] = 3;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8400'" . '&$select=ID';
        } else if($countryCode == 'CH') {
            $accounting['vatCode'] = '00';
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8338'" . '&$select=ID';
        } else if ($countryCode != 'DE' && $ustId == '') {
            $accounting['vatCode'] = 3;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8400'" . '&$select=ID';
        } else {
            $accounting['vatCode'] = 11;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8125'" . '&$select=ID';
        }

        $accounting['accountSales'] = $this->get($uri)->d->results[0]->ID;

        return $accounting;
    }

    /**
     * Fetch classification guid by code
     *
     * @param $customerType
     * @return String
     */
    protected function getClassification($customerType)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/AccountClassifications?$filter=trim(Code) eq '
            . "'" . $customerType . "'" . '&$select=ID';

        $results = Cache::remember('exact.classification.' . $customerType, 129600, function () use ($uri) {
            return $this->get($uri)->d->results;
        });

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch price list guid by name
     *
     * @param $name
     * @return String
     */
    protected function getPriceListId($name)
    {
        $uri = '/api/v1/'. $this->division .'/sales/PriceLists?$filter=Description eq '
            . "'" . $name . "'";

        $results = Cache::remember('exact.priceList.' . $name, 129600, function () use ($uri) {
            return $this->get($uri)->d->results;
        });

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch payment condition short code by payment method
     *
     * @param $paymentMethod
     * @param $notices
     * @return String
     */
    protected function getPaymentCondition($paymentMethod, $notices)
    {
        if ($notices == 'Bezahlt' && $paymentMethod == 'Vorkasse') return 'VZ';

        return collect([
            'Rechnung' => '01',
            'Paypal' => 'PP',
            'Vorkasse' => 'V2',
            'Sofortüberweisung' => 'SO'
        ])->filter(function($code, $condition) use ($paymentMethod) {
            return $condition == $paymentMethod;
        })->first();
    }

    /**
     * Check if any data has changed and then update record
     *
     * @param $accountId
     * @param $companyData
     * @param $deliveryLang
     */
    protected function checkAddressChanges($accountId, $companyData, $deliveryLang)
    {
        $account = $this->getAccount($accountId);

        $newAddress = [
            $companyData->name,
            $companyData->street . ' ' . $companyData->house_number,
            $companyData->zip_code,
            $companyData->city
        ];

        $oldAddress = [
            $account->Name,
            $account->AddressLine1,
            $account->Postcode,
            $account->City
        ];

        if (count(array_diff($newAddress, $oldAddress)) < 1) return;

        return $this->updateAccount($companyData, $deliveryLang, $accountId);
    }

    /**
     * Send GET request to exact api
     *
     * @param $uri
     * @return Object
     */
    protected function get($uri)
    {
        try {
           $response = $this->client->request('GET', $uri, [
               'headers' => [
                   'Accept' => 'application/json',
                   'authorization' => 'Bearer ' . Cache::get(Auth::id() . '.access_token')
               ]
           ]);
        } catch (ClientException $e) {
            return ['error' => $e->getMessage()];
        } catch (ServerException $e) {
            return ['error' => $e->getMessage()];
        } catch (RequestException $e) {
            return ['error' => $e->getMessage()];
        }

        return json_decode($response->getBody());
    }

    /**
     * Send POST request to exact api
     *
     * @param $uri
     * @param $data
     * @param $type
     * @param $token
     * @return Object
     */
    protected function post($uri, $data, $type = 'json', $token = true)
    {
        $headers = [
            'Accept' => 'application/json',
            'authorization' => 'Bearer ' . Cache::get(Auth::id() . '.access_token')
        ];

        if (!$token)  array_pop($headers);

        try {
            $response = $this->client->request('POST', $uri, [
                'headers' => $headers,
                $type => $data
            ]);
        } catch (ClientException $e) {
            return ['error' => $e->getMessage()];
        } catch (ServerException $e) {
            return ['error' => $e->getMessage()];
        } catch (RequestException $e) {
            return ['error' => $e->getMessage()];
        }

        return json_decode($response->getBody());
    }

    /**
     * Send PUT request to exact api
     *
     * @param $uri
     * @param $data
     * @param $type
     * @return Object
     */
    protected function put($uri, $data, $type = 'json')
    {
        try {
            $response = $this->client->request('PUT', $uri, [
                'headers' => [
                    'Accept' => 'application/json',
                    'authorization' => 'Bearer ' . Cache::get(Auth::id() . '.access_token')
                ],
                $type => $data
            ]);
        } catch (ClientException $e) {
            return ['error' => $e->getMessage()];
        } catch (ServerException $e) {
            return ['error' => $e->getMessage()];
        } catch (RequestException $e) {
            return ['error' => $e->getMessage()];
        }

        return json_decode($response->getBody());
    }

    /**
     * Check existing tokens and return/refresh
     *
     * @return bool
     */
    protected function checkToken()
    {
        if (Cache::get(Auth::id() . '.access_token')) {
            return 'found access token';
            return true;
        } else if(Cache::get(Auth::id() . '.refresh_token')) {
            return 'found refresh token';
            return $this->refreshTokens();
        } else {
            dump('found nothing biatch!');
            return redirect()->to('exact/login');
            // return $this->refreshTokens(Cache::get('1.refresh_token'));
        }
    }

    /**
     * Refresh api tokens
     *
     * @param $token
     * @return bool
     */
    protected function refreshTokens($token = null)
    {
        $uri = '/api/oauth2/token';
        $refreshToken = $token ? $token : Cache::get(Auth::id() . '.refresh_token');

        $data = [
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'client_id' => config('exact.client_id'),
            'client_secret' => config('exact.client_secret')
        ];

        $body = $this->post($uri, $data, 'form_params', false);

        Cache::put(Auth::id() . '.access_token', $body->access_token, $body->expires_in / 60);
        Cache::forever(Auth::id() . '.refresh_token', $body->refresh_token);

        return true;
    }
}