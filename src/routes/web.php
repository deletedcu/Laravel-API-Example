<?php

Route::get('/exact/login', 'OAuthController@login');
Route::get('/exact/callback', 'OAuthController@callback');