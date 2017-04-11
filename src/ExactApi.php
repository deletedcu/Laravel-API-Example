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

    public function createSalesOrder()
    {
        this->checkToken();
    }

    protected function checkToken()
    {
        if (Cache::get(Auth::id() . '.access_token')) {
            dd('hat token');
            return true;
        } else if(Cache::get(Auth::id() . '.refresh_token')) {
            dd('need refresh');
            $this->refreshToken();
        } else {
           $uri = '/api/oauth2/auth?client_id=' . env('CLIENT_ID')
               . '&redirect_uri=' . env('REDIRECT_URI')
               . '&response_type=code';

           header('Location: ' . config('exact.base_uri') . $uri, TRUE, 302);
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