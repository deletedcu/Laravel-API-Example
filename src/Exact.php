<?php

namespace BohSchu\Exact;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class Exact
{
    private $client;

    public function __construct()
    {
        $this->client = new Client(['base_uri' => config('exact.base_uri')]);
    }

    public function createSalesOrder()
    {
        dd(config('exact.base_uri'));
        $this->checkToken();
    }

    protected function checkToken()
    {
        try {
            $response = $this->client->request('GET', '/api/v1/current/Me', [
                'headers' => [
                    'Accept' => 'application/json',
                    'authorization' => 'Bearer ' . Cache::get(Auth::id() . '.access_token')
                ]
            ]);
        } catch (ClientException $e) {
            dd($e->getResponse());
            if ($e->getResponse()->getStatusCode() == 401 && Cache::get(Auth::id() . '.refresh_token')) {
                $this->refreshToken();
            } else {
                $uri = '/api/oauth2/auth?client_id='
                    . env('CLIENT_ID')
                    . '&redirect_uri=' . env('REDIRECT_URI')
                    . '&response_type=code';

                return redirect()->to(config('exact.base_uri') . $uri);
            }
        }
    }

    // public function send($data)
    // {
    //     $response = $this->client->request('GET', '/api/v1/current/Me', [
    //         'headers' => [
    //             'Accept' => 'application/json',
    //             'authorization' => 'Bearer ' . Cache::get('access_token')
    //         ]
    //     ]);

    //     dd($reponse);
    // }
}