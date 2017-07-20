<?php

namespace BohSchu\Exact;

use Carbon\Carbon;
use GuzzleHttp\Client;
use BohSchu\Exact\ExactHelperTrait;

class ExactApi
{
    use ExactHelperTrait;

    /**
     * Guzzle Client
     *
     * @var $client
     */
    private $client;

    /**
     * Exact ERP Division Number
     *
     * @var $division
     */
    private $division;

    /**
     * Instantiate the guzzle http client and set division code
     */
    public function __construct()
    {
        $this->division = config('exact.division');
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    /**
     * @return Object
     */
    public function getQuotation()
    {
        $uri = "/api/v1/{$this->division}/read/crm/Documents"
        . '?$filter=ID eq guid' . "'457ff634-3ba2-4453-aa51-045d993ad3e4'" . '&$select=ID,Attachments';

        $document = $this->get($uri)->d->results[0];

        return $this->get($document->Attachments->_deffered->uri);
        // $uri = "/api/v1/{$this->division}/read/crm/DocumentsAttachments"
        //     . '?$filter=ID eq guid' . "'" . '3efbda32-05f2-414f-a1e1-f9609d4719d8' . "'" . '&$select=AttachmentFileName';

        return $this->get($uri);
    }

    /**
     * @param $select
     * @return Object
     */
    public function getSalesOrders($select)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division .'/salesorder/SalesOrders'
            . '?$filter=startswith(tolower(YourRef), '. "'e'" .') eq true and substringof('. "'/'" .', YourRef) eq false'
            . '&$expand=SalesOrderLines'
            . '&$select=' . $select;

        $response = $this->get($uri);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        return $response->d->results;
    }

    /**
     * Get all purchase orders by its supplier code (Supplier Orders)
     *
     * @param $supplierCode
     * @param $select
     * @return Array
     */
    public function getPurchaseOrdersBySupplier($supplierCode, $select)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division
            .'/purchaseorder/PurchaseOrders?'
            . '$filter=trim(SupplierCode) eq ' . "'" . $supplierCode . "'"
            . ' and startswith(tolower(Description), '. "'moedel'" .') eq true and DropShipment eq false'
            . '&$expand=PurchaseOrderLines'
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    /**
     * Get all goods deliveries by its shipping method
     *
     * @param $shippingMethod
     * @return Collection
     */
    public function getGoodsDeliveries($shippingMethod)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division .'/salesorder/GoodsDeliveries'
            . '?$filter=trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . ' and substringof(' . "'Gedruckt'" . ',Remarks) eq false'
            . ' or Remarks eq null and trim(ShippingMethodCode) eq ' . "'" . $shippingMethod . "'"
            . '&$select=EntryID,DeliveryAccountName, DeliveryAddress,DeliveryContact,Description,DeliveryNumber,ShippingMethodCode,Remarks';

        $response = $this->get($uri);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        $results = $response->d->results;

        return collect($results)->map(function($delivery) {
            $contact = $this->getContact($delivery->DeliveryContact, 'Email,Phone');

            $delivery->address = $this->getAddress(
                $delivery->DeliveryAddress,
                'AccountName,AddressLine1,AddressLine2,AddressLine3,City,ContactName,Country,Postcode'
            );

            $delivery->Email = $contact[0]->Email ?? '';
            $delivery->Phone = $contact[0]->Phone ?? '';

            return $delivery;
        });
    }

    /**
     * Update a goods delivery by its id and given data
     *
     * @param $id
     * @param $data
     * @return Array
     */
    public function updateGoodsDeliveries($id, $data)
    {
        $this->checkToken();

        $uri = '/api/v1/'. $this->division
            .'/salesorder/GoodsDeliveries(guid' . "'" . $id . "'" . ')';

        return $this->put($uri, $data);
    }

    /**
     * Create a new quotation
     *
     * @param $quotation
     * @return Array
     */
    public function createQuotation($quotation)
    {
        $this->checkToken();

        if ($quotation->company->erp_id != '') {
            $account = $quotation->company->erp_id;
        } else if ($accountId = $this->getAccountId($quotation->company)) {
            $account = $accountId;
        } else {
            $account = $this->createAccount($quotation->company, $quotation->delivery->language->code, false, 'U');
        }

        if (is_array($account) && array_key_exists('error', $account)) return $account;

        if ($quotation->user->erp_id != '') {
            $contact = $quotation->user->erp_id;
        } else if ($contactId = $this->getContactId($quotation->user, $account)) {
            $contact = $contactId;
        } else {
            $contact = $this->createContact($quotation->user, $account);
        }

        if (is_array($contact) && array_key_exists('error', $contact)) return $contact;

        if ($quotation->delivery->erp_id != '') {
            $address = $quotation->delivery->erp_id;
        } else if ($addressId = $this->getAddressId($quotation->delivery, $account)) {
            $address = $addressId;
        } else {
            $address = $this->createAddress($quotation->delivery, $account);
        }

        if (is_array($address) && array_key_exists('error', $address)) return $address;

        $quotationLines = $this->getItemIds(
            $quotation->details,
            $quotation->company->language->code,
            $quotation->delivery->language->code,
            $quotation->company->ustid,
            false
        );

        $description = 'Angebotsanfrage ' . Carbon::now()->format('d.m.Y');
        $description .= $quotation->details->contains(function($detail, $key) {
            return $detail['file'] != '';
        }) ? ' (Mit Datei)' : '';

        $data = [
            'OrderAccount' => $account,
            'OrderAccountContact' => $contact,
            'DeliveryAddress' => $address,
            'Description' => $description,
            'QuotationLines' => $quotationLines,
            'Remarks' => $quotation->comments
        ];

        $response = $this->post('/api/v1/'. $this->division .'/crm/Quotations', $data);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        return $response->d;
    }

    /**
     * Create a new sales order (Customer Order)
     *
     * @param $order
     * @return Array
     */
    public function createSalesOrder($order)
    {
        $this->checkToken();

        if ($order->company->erp_id != '') {
            $account = $order->company->erp_id;
        } else if ($accountId = $this->getAccountId($order->company)) {
            $account = $accountId;
        } else {
            $account = $this->createAccount($order->company, $order->delivery->language->code, $order->digital_bill, $order->customer_type);
        }

        $this->checkAddressChanges($account, $order->company, $order->delivery->language->code);

        if (is_array($account) && array_key_exists('error', $account)) return [$account, null, null, null];

        if ($order->user->erp_id != '') {
            $contact = $invoiceContact = $order->user->erp_id;
        } else if ($contactId = $invoiceContact = $this->getContactId($order->user, $account)) {
            $contact = $contactId;
        } else {
            $contact = $invoiceContact = $this->createContact($order->user, $account);
        }

        if (is_array($contact) && array_key_exists('error', $contact)) return [$contact, null, null, null];

        if ($order->digital_bill) {
            $eBillData = (object) [
                'salutation' => '',
                'first_name' => 'E-Mail',
                'last_name' => 'eRechnung',
                'email' => $order->company->company_email ?? $order->user->email
            ];

            $invoiceContact = $this->getContactId($eBillData, $account) ?? $this->createContact($eBillData, $account);
        }

        if ($order->delivery->erp_id != '') {
            $address = $order->delivery->erp_id;
        } else if ($addressId = $this->getAddressId($order->delivery, $account)) {
            $address = $addressId;
        } else {
            $address = $this->createAddress($order->delivery, $account);
        }

        if (is_array($address) && array_key_exists('error', $address)) return [$address, null, null, null];

        $paymentCondition = $this->getPaymentCondition($order->payment_method, $order->notices);

        $salesOrderLines = $this->getItemIds(
            $order->details,
            $order->company->language->code,
            $order->delivery->language->code,
            $order->company->ustid
        );

        if ($order->delivery_costs != '0.00' && $order->delivery_costs != '') {
            $salesOrderLines[] = $this->getDeliveryCosts(
                $order->delivery_costs,
                $order->company->language->code,
                $order->delivery->language->code,
                $order->company->ustid
            );
        }

        $data = [
            'OrderDate' => $order->created_at->format('Y-m-d'),
            'OrderedBy' => $account,
            'OrderedByContactPerson' => $contact,
            'DeliveryAddress' => $address,
            'InvoiceToContactPerson' => $invoiceContact,
            'YourRef' => $order->id,
            'Remarks' => $order->comments,
            'PaymentCondition' => $paymentCondition,
            'PaymentReference' => $order->digital_bill ? 'eRg.' : '',
            'SalesOrderLines' => $salesOrderLines
        ];

        $response = $this->post('/api/v1/'. $this->division .'/salesorder/SalesOrders', $data);

        if (is_array($response) && array_key_exists('error', $response)) return [$response, null, null, null];

        return [$response->d->OrderNumber, $account, $contact, $address];
    }

    /**
     * @param $salesOrderId
     * @param $yourRef
     * @param $shopOrderId
     * @return Object
     */
    public function updateSalesOrder($salesOrderId, $yourRef, $shopOrderId)
    {
        $data = [
            'YourRef' => $yourRef . '/' . $shopOrderId
        ];

        $uri = '/api/v1/'. $this->division
            .'/salesorder/SalesOrders(guid' . "'" . $salesOrderId . "'" . ')';

        return $this->put($uri, $data);
    }

    /**
     * @param $account
     * @return mixed
     */
    public function getAccount($account)
    {
        $uri = '/api/v1/'
            . $this->division .'/crm/Accounts?$filter=ID eq guid'. "'" . $account . "' "
            . '&select=Name,AddressLine1';

        $results = $this->get($uri)->d->results;

        return count($results) > 0 ? $results[0] : null;
    }

    /**
     * Create a new account (Customer)
     *
     * @param  $account
     * @param $deliveryLang
     * @param bool $digitalBill
     * @param null $customerType
     * @return String
     */
    public function createAccount($account, $deliveryLang,  $digitalBill = false, $customerType = null)
    {
        $this->checkToken();

        $accounting = $this->getAccountingCodes($deliveryLang, $account->ustid);

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
            'PriceList' => $this->getPriceListId('VK Preisliste Shop'),
            'InvoicingMethod' => $digitalBill ? 2 : 1
        ];

        if ($customerType) $data['Classification1'] = $this->getClassification($customerType);

        $response = $this->post('/api/v1/'. $this->division .'/crm/Accounts', $data);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        return $response->d->ID;
    }

    /**
     * Update an existing account in erp
     *
     * @param $account
     * @param $deliveryLang
     * @param bool $id
     * @return Array
     */
    public function updateAccount($account, $deliveryLang, $id = false)
    {
        $this->checkToken();

        $id = $id ?: $this->getAccountId($account, false);

        $accounting = $this->getAccountingCodes($deliveryLang, $account->ustid);

        $data = [
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
        ];

        $uri = '/api/v1/'. $this->division
            .'/crm/Accounts(guid' . "'" . $id . "'" . ')';

        return $this->put($uri, $data);
    }

    /**
     * Fetch contact by id
     *
     * @param $contactId
     * @param $select
     * @return Array
     */
    public function getContact($contactId, $select)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Contacts?$filter=ID eq guid' . "'" . $contactId . "'"
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    /**
     * Create a new contact (Customer User)
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
            'LastName' => $contact->last_name ?? '',
            'Email' => $contact->email ?? '',
            'Phone' => $contact->phone ?? '',
            'Title' => strtoupper($contact->salutation) ?? '',
            'JobTitleDescription' => $contact->position ?? ''
        ];

        $response = $this->post('/api/v1/'. $this->division .'/crm/Contacts', $data);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        return $response->d->ID;
    }

    /**
     * Update an existing contact in erp
     *
     * @param $contact
     * @return Array
     */
    public function updateContact($contact)
    {
        $this->checkToken();

        $accountId = $this->getAccountId($contact->company_id, false);
        $contactId = $this->getContactId($contact, $accountId);

        $data = [
            'FirstName' => $contact->first_name ?? '',
            'LastName' => $contact->last_name ?? '',
            'Email' => $contact->email ?? '',
            'Phone' => $contact->phone ?? '',
            'Title' => strtoupper($contact->salutation) ?? '',
            'JobTitleDescription' => $contact->position ?? ''
        ];

        $uri = '/api/v1/'. $this->division
            .'/crm/Contacts(guid' . "'" . $contactId . "'" . ')';

        return $this->put($uri, $data);
    }

    /**
     * Fetch address by id
     *
     * @param $addressId
     * @param $select
     * @return Array
     */
    public function getAddress($addressId, $select)
    {
        $uri = '/api/v1/'. $this->division
            .'/crm/Addresses?$filter=ID eq guid' . "'" . $addressId . "'"
            . '&$select=' . $select;

        return $this->get($uri)->d->results;
    }

    /**
     * Create a new address (Customer Delivery Address)
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

        $response = $this->post('/api/v1/'. $this->division .'/crm/Addresses', $data);

        if (is_array($response) && array_key_exists('error', $response)) return $response;

        return $response->d->ID;
    }

    /**
     * Update an existing address in erp
     *
     * @param $address
     * @return Array
     */
    public function updateAddress($address)
    {
        $this->checkToken();

        $accountId = $this->getAccountId($address->company_id, false);
        $addressId = $this->getAddressId($address, $accountId);

        $data = [
            'AddressLine1' => $address->delivery_street . ' ' . $address->delivery_house_number,
            'AddressLine2' => $address->delivery_additional,
            'AddressLine3' => $address->delivery_name,
            'Postcode' => $address->delivery_zip_code,
            'City' => $address->delivery_city,
            'Country' => $address->language->code ?? 'DE',
            'Type' => 4
        ];

        $uri = '/api/v1/'. $this->division
            .'/crm/Addresses(guid' . "'" . $addressId . "'" . ')';

        return $this->put($uri, $data);
    }
}