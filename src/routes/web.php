<?php

Route::get('/exact/login', '\BohSchu\Exact\Controllers\OAuthController@login');
Route::get('/exact/callback', '\BohSchu\Exact\Controllers\OAuthController@callback');