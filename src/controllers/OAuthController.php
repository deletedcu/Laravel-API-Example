<?php

namespace BohSchu\Exact\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    private $guzzle;

    public function __construct()
    {
        $this->guzzle = new Client();
    }

    public function login()
    {
        $uri = '/api/oauth2/auth?client_id=' . env('CLIENT_ID')
               . '&redirect_uri=' . env('REDIRECT_URI')
               . '&response_type=code';

        return redirect()->to(config('exact.base_uri') . $uri);
    }

    /**
     * Catch the callback and the code and perform token request
     *
     * @param Request $request
     * @return function
     */
    public function callback(Request $request)
    {
        $body = $this->guzzle->request('POST', '/api/oauth2/token', [
            'code' => $request->get('code'),
            'redirect_uri' => env('REDIRECT_URI'),
            'grant_type' => 'authorization_code',
            'client_id' => env('CLIENT_ID'),
            'client_secret' => env('CLIENT_SECRET')
        ]);

        Cache::forever('access_token', $body['access_token']);
        Cache::forever('refresh_token', $body['refresh_token']);

        return redirect()->to('/orders/transfer');
    }
}
