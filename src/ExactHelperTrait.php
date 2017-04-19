<?php

namespace BohSchu\Exact;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

trait ExactHelperTrait
{
    /**
     * Fetch account guid by code
     *
     * @param $account
     * @param $digitalBill
     * @return String
     */
    protected function getAccountId($account, $digitalBill)
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
     * Fetch contact by id
     *
     * @param $contactId
     * @param $select
     * @return Array
     */
    protected function getContact($contactId, $select)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Contacts?$filter=ID eq guid' . "'" . $contactId . "'"
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
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
        $uri = '/api/v1/'. $this->division
            .'/crm/Addresses?$filter=Account eq guid' . "'" . $accountId . "'"
            .' and startswith(trim(AddressLine1),'
            . "'" . $address->delivery_street . "') " . 'eq true'
            .' and Postcode eq '. "'" . $address->delivery_zip_code . "'" . '&$select=ID';

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch address by id
     *
     * @param $addressId
     * @param $select
     * @return Array
     */
    protected function getAdress($addressId, $select)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Addresses?$filter=ID eq guid' . "'" . $addressId . "'"
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    /**
     * Fetch product guid for salesOrderLines
     *
     * @param  $products
     * @param  $countryCode
     * @param  $deliveryCountryCode
     * @return Array
     */
    protected function getItemIds($products, $countryCode, $deliveryCountryCode)
    {
        $return = [];

        foreach ($products as $key => $value) {
            $uri = '/api/v1/'. $this->division .'/logistics/Items?$filter=trim(Code) eq ' . "'" . $value->variant->sku . "'" . '&$select=ID';
            $itemId = $this->get($uri)->d->results;

            if(isset($itemId[0]->ID)) {
                $return[$key]['Item'] = $itemId[0]->ID;
                $return[$key]['Quantity'] = $value->amount;
                $return[$key]['Notes'] = $value->individualized;

                if (isset($value->price)) {
                    $return[$key]['NetPrice'] = $value->price;
                }
            }

            if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
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
     * @return Array
     */
    protected function getDeliveryCosts($cost, $countryCode, $deliveryCountryCode)
    {
        $uri = '/api/v1/'. $this->division
            .'/logistics/Items?$filter=trim(Code) eq '
            . "'Versand " . $deliveryCountryCode . "'" . '&$select=ID';

        $return = [
            'Item' => $this->get($uri)->d->results[0]->ID,
            'Quantity' => 1,
            'NetPrice' => (float) $cost
        ];

        if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
            $return['VATCode'] = 3;
        }

        return $return;
    }

    /**
     * Fetch the right accounting codes by country code
     *
     * @param $countryCode
     * @return Array
     */
    protected function getAccountingCodes($countryCode)
    {
        if ($countryCode == 'DE') {
            $accounting['vatCode'] = 3;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8400'" . '&$select=ID';
        } else if($countryCode == 'CH') {
            $accounting['vatCode'] = 0;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8338'" . '&$select=ID';
        } else {
            $accounting['vatCode'] = 11;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8125'" . '&$select=ID';
        }

        $accounting['accountSales'] = $this->get($uri)->d->results[0]->ID;

        return $accounting;
    }

    /**
     * Fetch pricelist guid by name
     *
     * @param $name
     * @return String
     */
    protected function getPriceListId($name)
    {
        $uri = '/api/v1/'. $this->division .'/sales/PriceLists?$filter=Description eq '
            . "'" . $name . "'";

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch payment condition short code by payment method
     *
     * @param $paymentMethod
     * @return String
     */
    protected function getPaymentCondition($paymentMethod)
    {
        return collect([
            'Rechnung' => '01',
            'Paypal' => 'PP',
            'Vorkasse' => 'V2',
            'Vorkasse (Sonderfall)' => 'V2',
            'Sofortüberweisung' => 'SO'
        ])->filter(function($code, $condition) use ($paymentMethod) {
            return $condition == $paymentMethod;
        })->first();
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
            return \GuzzleHttp\Psr7\str($e->getResponse());
        } catch (ServerException $e) {
            return \GuzzleHttp\Psr7\str($e->getResponse());
        } catch (RequestException $e) {
            return \GuzzleHttp\Psr7\str($e->getResponse());
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
            $response = $this->client->request('POSTi', $uri, [
                'headers' => $headers,
                $type => $data
            ]);
        } catch (ClientException $e) {
            dd($e->getMessage());
        } catch (ServerException $e) {
            dd($e->getMessage());
        } catch (RequestException $e) {
            dd($e->getMessage());
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
            return \GuzzleHttp\Psr7\str($e->getResponse());
        } catch (ServerException $e) {
            return \GuzzleHttp\Psr7\str($e->getResponse());
        } catch (RequestException $e) {
            return \GuzzleHttp\Psr7\str($e->getResponse());
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
            return true;
        } else if(Cache::get(Auth::id() . '.refresh_token')) {
            return $this->refreshTokens();
        } else {
            return $this->refreshTokens(Cache::get('1.refresh_token'));
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
            'client_id' => env('CLIENT_ID'),
            'client_secret' => env('CLIENT_SECRET')
        ];

        $body = $this->post($uri, $data, 'form_params', false);

        Cache::put(Auth::id() . '.access_token', $body->access_token, $body->expires_in / 60);
        Cache::forever(Auth::id() . '.refresh_token', $body->refresh_token);

        return true;
    }
}