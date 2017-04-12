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
     * Fetch company guid by code
     *
     * @param $account
     * @return String
     */
    protected function getAccountId($account, $digitalBill)
    {
        dd('getid');
        $id = strlen($account['id']) == 5 ? '10' . (string) $account['id'] : $account['id'];

        $uri = '/api/v1/'
            . $this->division .'/crm/Accounts?$filter=startswith(trim(Code),' . "'" . $id . "') "
            . 'eq true&$select=ID';

        $results = $this->get($uri)->d->results;

        return $results ? $results[0]->ID : false;
    }

    /**
     * Fetch company user guid by code
     *
     * @param $user
     * @return String
     */
    protected function getContactId($contact, $accountId)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Contacts?$filter=Account eq guid' . "'" . $accountId . "'"
            . ' and LastName eq ' . "'" . $contact->last_name . "'"
            . ' and FirstName eq ' . "'" . $contact->first_name . "'" . '&$select=ID';

        $results = $this->get($uri)->d->results;

        return $results ? $results[0]->ID : false;
    }

    /**
     * Fetch company delivery adress guid by adress
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
            . "'" . $address->delivery_street . $address->delivery_house_number . "') " . 'eq true'
            .' and Postcode eq '. "'" . $address->delivery_zip_code . "'" . '&$select=ID';

        $results = $this->get($uri)->d->results;

        return $results ? $results[0]->ID : false;
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

        foreach ($details as $key => $value) {
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
     * Return the right delivery costs
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
     * Define accounting information for account
     *
     * @param $languageCode
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
     * Get pricelist id from erp
     *
     * @return String
     */
    protected function getPriceListId($name)
    {
        $uri = '/api/v1/'. $this->division .'/sales/PriceLists?$filter=Description eq '
            . "'" . $name . "'";

        $results = $this->get($uri)->d->results;

        return $results ? $results[0]->ID : false;
    }

    /**
     * Get payment condition short code
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
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (ServerException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (RequestException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        }

        return json_decode($response->getBody());
    }

    protected function post($uri, $data, $type = 'json')
    {
        try {
            $response = $this->client->request('POST', $uri, [
                'headers' => [
                    'Accept' => 'application/json',
                    'authorization' => 'Bearer ' . Cache::get(Auth::id() . '.access_token')
                ],
                $type => $data
            ]);
        } catch (ClientException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (ServerException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (RequestException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        }

        return json_decode($response->getBody());
    }

    protected function checkToken()
    {
        if (Cache::get(Auth::id() . '.access_token')) {
            return true;
        } else if(Cache::get(Auth::id() . '.refresh_token')) {
            return $this->refreshTokens();
        } else {
            return false;
        }
    }

    /**
     * Refresh api tokens
     *
     * @return bool
     */
    protected function refreshTokens()
    {
        try {
            $body = $this->client->request('POST', '/api/oauth2/token', [
                'headers' =>  [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'refresh_token' => Cache::get(Auth::id() . '.refresh_token'),
                    'grant_type' => 'refresh_token',
                    'client_id' => env('CLIENT_ID'),
                    'client_secret' => env('CLIENT_SECRET')
                ]
            ]);
        } catch (ClientException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (ServerException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        } catch (RequestException $e) {
            dd(\GuzzleHttp\Psr7\str($e->getResponse()));
        }

        $body = json_decode($body->getBody());

        Cache::put(Auth::id() . '.access_token', $body->access_token, $body->expires_in / 60);
        Cache::forever(Auth::id() . '.refresh_token', $body->refresh_token);

        return true;
    }
}