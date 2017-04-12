<?php

namespace BohSchu\Exact;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ExactApi
{
    private $client;

    public function __construct()
    {
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    public function createSalesOrder($order)
    {
        dd($this->getPaymentCondition($order->payment_method));

        if (! $this->checkToken()) {
            return false;
        }

        $account = $this->getAccountId($order->company, $order->digital_bill)
                ?? $this->createAccount($order->company, $order->digital_bill);

        $contact = $this->getContactId($order->user, $account)
                ?? $this->createContact($order->user, $account);

        $paymentCondition = $this->getPaymentCondition($order->payment_method);

        $salesOrderLines = $this->getItemIds(
            $prder->details,
            $prder->company->language->code,
            $prder->delivery->language->code
        );

        if ($order->delivery_costs != '0.00' || $order->delivery_costs != '') {
            $salesOrderLines[] = $this->getDeliveryCosts(
                $order->company->language->code,
                $order->delivery_costs,
                $order->delivery->language->code
            );
        }

        $dataToTransfer = [
            'OrderDate' => $prder->created_at,
            'OrderedBy' => $company,
            'OrderedByContactPerson' => $user,
            'YourRef' => $order->id,
            'Remarks' => $order->comments,
            'PaymentCondition' => $paymentCondition,
            'PaymentReference' => $digitalBill ? 'eRg.' : '',
            'SalesOrderLines' => $salesOrderLines,
            // 'WarehouseID' => '0c5b86c7-1e97-4ea4-b334-a6235c0718fa'
        ];

        $dataToTransfer['DeliveryAddress'] = $this->getCompanyDeliveryId($order->delivery, $company);
    }

    /**
     * Create a new account in exact
     *
     * @param  $account
     * @return String
     */
    public function createAccount($account, $digitalBill = false)
    {
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
            'SalesVATCode' => $accounting->vatCode,
            'GLAccountSales' => $accounting->accountSales,
            // 'Classification2' => $this->getClassification($company['customer_type']),
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
    protected function createContact($contact, $accountId)
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
}