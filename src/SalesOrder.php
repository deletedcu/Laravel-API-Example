<?php

namespace BohSchu\Exact;

use GuzzleHttp\Client;

class SalesOrder
{
    private $client;

    public function __construct($client)
    {
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    public function send($data)
    {
        $response = $this->client->request('GET', '/api/v1/current/Me', [
            'headers' => [
                'Accept' => 'application/json',
                'authorization' => 'Bearer ' . Cache::get('access_token')
            ]
        ]);

        dd($reponse);
    }
}