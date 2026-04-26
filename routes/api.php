<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['auth:user', 'jwt.auth']], function ($router) {
    $router->post('/order', 'OrderController@store')->middleware('throttle:order');
    // ->middleware('throttle:10,1');
});
