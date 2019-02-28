<?php

namespace BohSchu\Exact;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\RequestException;

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
        $name = str_replace("'", '%27', $name);
        $name = str_replace("`", '%60', $name);
        $name = str_replace("ü", '%FC', $name);
        $name = str_replace("ä", '%E4', $name);
        $name = str_replace("ö", '%F6', $name);
        $name = str_replace("ß", '%DF', $name);

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

            $itemId = Cache::remember('exact.item.' . $value->variant->sku, 1440, function () use ($uri) {
                return $this->get($uri)->d->results;
            });

            // Prepare return data
            if(isset($itemId[0]->ID)) {
                $return[$key]['Item'] = $itemId[0]->ID;
                $return[$key]['Quantity'] = $value->amount;
                $return[$key]['Notes'] = $value->individualized;

                if (isset($value->price)) {
                    $return[$key]['NetPrice'] = $value->price;
                }

                if(isset($value->individual_price)) {
                    $return[$key]['NetPrice'] = $value->individual_price;   
                }

                if ($deliveryDate) {
                    $return[$key]['DeliveryDate'] = Carbon::today()->addWeekDays($value->variant->deliveryDays)->format('Y-m-d');
                }
            } else {
                $return[$key] = 'false';
            }

            // Set VATCode for specific country and delivery
            if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
                $return[$key]['VATCode'] = 3;
            } else if($countryCode == 'CH' && $deliveryCountryCode == 'CH') {
                $return[$key]['VATCode'] = '000';
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

        // Get the right VATCode for specific country and delivery
        if ($countryCode != 'DE' && $deliveryCountryCode == 'DE') {
            $return['VATCode'] = 3;
        } else if($countryCode == 'CH' && $deliveryCountryCode == 'CH') {
            $return['VATCode'] = '000';
        } else if($countryCode != 'DE' && $deliveryCountryCode != 'DE' && $ustId == '') {
            $return['VATCode'] = 3;
        }

        return $return;
    }

    /**
     * Get forwarding_costs article
     *
     * @param $cost
     * @param $countryCode
     * @param $deliveryCountryCode
     * @param $ustId
     * @return Array
     */
    protected function getForwardingCosts($cost, $deliveryCountryCode)
    {
        if ($deliveryCountryCode == 'DE') {
            $uri = '/api/v1/'.  $this->division .'/logistics/Items?$filter=trim(Code) eq ' . "'spedi DE'" .'&$select=ID';
            $sku = 'spedi DE';
            $code = 3;
        } else if ($deliveryCountryCode == 'LI' || $deliveryCountryCode == 'CH' || $deliveryCountryCode == 'RU' || $deliveryCountryCode == 'EGY' || $deliveryCountryCode == 'VE') {
            $uri = '/api/v1/'.  $this->division .'/logistics/Items?$filter=trim(Code) eq ' . "'spedi 3'" .'&$select=ID';
            $sku = 'spedi 3';
            $code = '000';
        } else {
            $uri = '/api/v1/'.  $this->division .'/logistics/Items?$filter=trim(Code) eq ' . "'spedi EU'" .'&$select=ID';
            $sku = 'spedi EU';
            $code = 11;
        }
        
        $itemId = Cache::remember('exact.item.' . $sku, 1440, function () use ($uri) {
            return $this->get($uri)->d->results;
        });
        
        if (isset($itemId[0]->ID)) {
            $return['Item'] = $itemId[0]->ID;
            $return['Quantity'] = 1;
            $return['NetPrice'] = $cost;
            $return['VATCode'] = $code;
            $return['DeliveryDate'] = Carbon::today()->format('Y-m-d');
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
    protected function getAccountingCodes($customerCountryCode, $deliveryCountryCode, $ustId)
    {
        if ($customerCountryCode == 'DE') {
            $accounting['vatCode'] = 3;
            $uri = '/api/v1/' . $this->division
                . '/financial/GLAccounts?$filter=trim(Code) eq ' . "'8400'" . '&$select=ID';
        } else if ($deliveryCountryCode == 'DE') {
            $accounting['vatCode'] = 3;
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8400'" . '&$select=ID';
        } else if($deliveryCountryCode == 'CH') {
            $accounting['vatCode'] = '000';
            $uri = '/api/v1/'. $this->division
                .'/financial/GLAccounts?$filter=trim(Code) eq ' . "'8338'" . '&$select=ID';
        } else if ($deliveryCountryCode != 'DE' && $ustId == '') {
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
     * Fetch classification guid by customer type (Kundenklassifizierung)
     *
     * @param $customerType
     * @return String
     */
    protected function getClassification($customerType)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/AccountClassifications?$filter=trim(Code) eq '
            . "'" . $customerType . "'" . '&$select=ID';

        $results = Cache::remember('exact.classification.' . $customerType, 7200, function () use ($uri) {
            return $this->get($uri)->d->results;
        });

        return count($results) > 0 ? $results[0]->ID : null;
    }

    /**
     * Fetch price list guid by price list name
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
        if ($notices == 'Bezahlt' && $paymentMethod == 'Vorkasse- 2% Skonto -Zahlung eingegangen') return 'VZ';
        if ($notices == 'Keine' && $paymentMethod == 'Vorkasse- 2% Skonto -Zahlung eingegangen') return 'V2';
        if ($notices == 'Bezahlt' && $paymentMethod == 'Vorkasse') return '0V';
        if ($notices == 'Keine' && $paymentMethod == 'Vorkasse') return 'VK';

        return collect([
            '10 Tage 2% Skonto, 30 Tage netto' => '01',
            '10 Tage 3% Skonto, 30 Tage netto' => '03',
            '7 Tage netto' => '7',
            '14 Tage 3% Skonto, 30 Tage netto' => '13',
            '14 Tage 2% Skonto, 30 Tage netto' => '15',
            '14 Tage 3% Skonto, 60 Tage netto' => '16',
            '30 Tage 2% Skonto, 60 Tage netto' => '17',
            '21 Tage 2% Skonto, 30 Tage netto' => '18',
            '30 Tage netto' => '30',
            'Paypal 2% Skonto' => 'PP',
            'Paypal' => '0P',
            'Vorkasse -2% Skonto, Lieferung erfolgt nach Zahlungseingang' => 'V2',
            'Vorkasse -Lieferung erfolgt nach Zahlungseingang' => 'VK',
            'Vorkasse- 2% Skonto -Zahlung eingegangen' => 'VZ',
            'Vorkasse' => '0V',
            'Sofortüberweisung 2% Skonto' => 'SO',
            'Sofortüberweisung' => '0S',
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
     * @param $digitalBill
     * @return mixed
     */
    protected function checkAddressChanges($accountId, $companyData, $deliveryLang, $digitalBill)
    {
        $account = $this->getAccount($accountId);
        $types = ['1' => '2', '0' => '1'];

        $newAddress = [
            $companyData->name,
            $companyData->street . ' ' . $companyData->house_number,
            $companyData->addition,
            $companyData->zip_code,
            $companyData->city
        ];


        $oldAddress = [
            $account->Name,
            $account->AddressLine1,
            $account->AddressLine2,
            $account->Postcode,
            $account->City
        ];

        if ($account->InvoicingMethod != $types[$digitalBill]) {
            $this->updateAccountInvoicing($types[$digitalBill], $accountId);
        }

        if (count(array_diff($newAddress, $oldAddress)) < 1) return;

        return $this->updateAccount($companyData, $deliveryLang, $accountId);
    }

    /**
     * Chech if user data has changed and update
     *
     * @param $contactId
     * @param $userData
     * @return mixed
     */
    protected function checkUserChanges($contactId, $userData)
    {
        $contact = $this->getContact($contactId, 'FirstName,LastName,Email,Phone');
        $contact = count( $contact) ? $contact[0] : ['error' => 'Ansprechpartner wurde in Exact nicht gefunden!'];

        if(array_key_exists('error', $contact)) return $contact;

        $newContact = [
            $userData->first_name,
            $userData->last_name,
            $userData->email,
            $userData->phone
        ];

        $oldContact = [
            $contact->FirstName,
            $contact->LastName,
            $contact->Email,
            $contact->Phone
        ];

        if (count(array_diff($newContact, $oldContact)) < 1) return;

        return $this->updateContact($userData);
    }

    /**
     * Check if delivery data has changes and update
     *
     * @param $addressId
     * @param $deliveryData
     * @return mixed
     */
    protected function checkDeliveryChanges($addressId, $deliveryData)
    {
        $address = $this->getAddress($addressId, 'AddressLine1,AddressLine2,AddressLine3,Postcode,City');
        $address = count($address) ? $address[0] : ['error' => 'Adresse wurde in Exact nicht gefunden!'];
        
        if(array_key_exists('error', $address)) return $address;
        
        $newDelivery = [
            $deliveryData->delivery_name,
            $deliveryData->delivery_street . ' ' . $deliveryData->delivery_house_number,
            $deliveryData->delivery_additional,
            $deliveryData->delivery_zip_code,
            $deliveryData->delivery_city
        ];

        $oldDelivery = [
            $address->AddressLine3,
            $address->AddressLine1,
            $address->AddressLine2,
            $address->Postcode,
            $address->City
        ];

        if (count(array_diff($newDelivery, $oldDelivery)) < 1) return;

        return $this->updateAddress($deliveryData);
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