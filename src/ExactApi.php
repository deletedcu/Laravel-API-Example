<?php

namespace BohSchu\Exact;

use GuzzleHttp\Client;
use BohSchu\Exact\ExactHelperTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\ClientException;

class ExactApi
{
    use ExactHelperTrait;

    private $client;

    private $division;

    public function __construct()
    {
        $this->division = env('DIVISION_CODE');
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    public function getPurchaseOrdersBySupplier($supplierCode, $select)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $uri = '/api/v1/'. $this->division
            .'/purchaseorder/PurchaseOrders?'
            . '$filter=trim(SupplierCode) eq ' . "'" . $supplierCode . "'"
            . ' and startswith(tolower(Description), '. "'moedel'" .') eq true and DropShipment eq false'
            . '&$expand=PurchaseOrderLines'
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    public function getGoodsDeliveries($shippingMethod)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $uri = '/api/v1/'. $this->division .'/salesorder/GoodsDeliveries'
            . '?$filter=trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . ' and substringof(' . "'Gedruckt'" . ',Remarks) eq false'
            . ' or Remarks eq null and trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . '&$select=EntryID,DeliveryAccountName, DeliveryAddress,DeliveryContact,Description,DeliveryNumber,ShippingMethodCode,Remarks';

        $results = $this->get($uri)->d->results;

        return collect($results)->map(function($delivery) {
            $contact = $this->getContact($delivery->DeliveryContact, 'Email,Phone');

            $delivery->address = $this->getAdress(
                $delivery->DeliveryAddress,
                'AccountName,AddressLine1,AddressLine2,AddressLine3,City,ContactName,Country,Postcode'
            );

            $delivery->Email = $contact[0]->Email ?? '';
            $delivery->Phone = $contact[0]->Phone ?? '';

            return $delivery;
        });
    }

    public function updateGoodsDeliveries($id, $data)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $uri = '/api/v1/'. $this->division
            .'/salesorder/GoodsDeliveries(guid' . "'" . $id . "'" . ')';

        return $this->put($uri, $data);
    }

    public function createSalesOrder($order)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $account = $this->getAccountId($order->company, $order->digital_bill)
                ?? $this->createAccount($order->company, $order->digital_bill);

        $contact = $this->getContactId($order->user, $account)
                ?? $this->createContact($order->user, $account);

        $address = $this->getAddressId($order->delivery, $account)
                ?? $this->createAddress($order->delivery, $account);

        $paymentCondition = $this->getPaymentCondition($order->payment_method);

        $salesOrderLines = $this->getItemIds(
            $order->details,
            $order->company->language->code,
            $order->delivery->language->code
        );

        if ($order->delivery_costs != '0.00' || $order->delivery_costs != '') {
            $salesOrderLines[] = $this->getDeliveryCosts(
                $order->delivery_costs,
                $order->company->language->code,
                $order->delivery->language->code
            );
        }

        $data = [
            'OrderDate' => $order->created_at->format('d.m.Y H:i:s'),
            'OrderedBy' => $account,
            'OrderedByContactPerson' => $contact,
            'DeliveryAddress' => $address,
            'YourRef' => $order->id,
            'Remarks' => $order->comments,
            'PaymentCondition' => $paymentCondition,
            'PaymentReference' => $order->digital_bill ? 'eRg.' : '',
            'SalesOrderLines' => $salesOrderLines
        ];

        return $this->post('/api/v1/'. $this->division .'/salesorder/SalesOrders', $data)->d->OrderNumber;
    }

    /**
     * Create a new account in exact
     *
     * @param  $account
     * @return String
     */
    public function createAccount($account, $digitalBill = false)
    {
        if ($this->checkToken() == false) {
            return false;
        }

        $accounting = $this->getAccountingCodes($account->language->code);

        $data = [
            'Code' => strlen($account->id) == 5 ? '10' . (string) $account->id : $account->id,
            'Name' => $account->name,
            'AddressLine3' => $account->name_2 ?? '',
            'AddressLine2' => $account->addition ?? '',
            'AddressLine1' => $account->street . ' ' . $account->house_number,
            'Postcode' => $account->zip_code,
            'City' => $account->city,
            'Email' => $account->company_email ?? '',
            'Phone' => $account->company_phone ?? '',
            'Status' => $account->customer_type ?? 'C',
            'VATNumber' => $account->language->code == 'CH' ? '' : str_replace(['.', '-'], '', $account->ustid),
            'Country' => $account->language->code,
            'SalesVATCode' => $accounting['vatCode'],
            'GLAccountSales' => $accounting['accountSales'],
            'PriceList' => $this->getPriceListId('VK Preisliste Shop')
        ];

        if ($digitalBill) {
            $data['InvoicingMethod'] = 2;
        }

        return $this->post('/api/v1/'. $this->division .'/crm/Accounts', $data)->d->ID;
    }

    /**
     * Create a new contact in exact
     *
     * @param  $contact
     * @param  $accountId
     * @return String
     */
    public function createContact($contact, $accountId)
    {
        $data = [
            'Account' => $accountId,
            'FirstName' => $contact->first_name ?? '',
            'LastName' => $contact->last_name,
            'Email' => $contact->email,
            'Phone' => $contact->phone,
            'Title' => strtoupper($contact->salutation),
            'JobTitleDescription' => $contact->position
        ];

        return $this->post('/api/v1/'. $this->division .'/crm/Contacts', $data)->d->ID;
    }

    /**
     * Create a new address in exact
     *
     * @param $address
     * @param $accountId
     * @return String
     */
    public function createAddress($address, $accountId)
    {
        $data = [
            'Account' => $accountId,
            'AddressLine1' => $address->delivery_street . ' ' . $address->delivery_house_number,
            'AddressLine2' => $address->delivery_additional,
            'AddressLine3' => $address->delivery_name,
            'Postcode' => $address->delivery_zip_code,
            'City' => $address->delivery_city,
            'Country' => $address->language->code ?? 'DE',
            'Type' => 4
        ];

        return $this->post('/api/v1/'. $this->division .'/crm/Addresses', $data)->d->ID;
    }
}