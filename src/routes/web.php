<?php

Route::get('/oauth2/callback', function() {
    dd(request()->all());
});