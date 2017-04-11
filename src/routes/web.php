<?php

Route::get('/exact/login', 'BohSchu\Exact\OAuthController@login');
Route::get('/exact/callback', 'OAuthController@callback');